<?php
/**
 * Plugin Name: SANTS Unbounce to Pipedrive Integration
 * Plugin URI: https://www.sants.co.za
 * Description: Handles webhooks from Unbounce for integration with Pipedrive and sends confirmation emails.
 * Version: 1.3
 * Author: Andries Bester
 * Author URI: https://www.sants.co.za
 */

add_action('admin_menu', 'sants_webhook_settings_menu');

function sants_webhook_settings_menu() {
    add_options_page('SANTS Webhook Settings', 'SANTS Webhook Settings', 'manage_options', 'sants-webhook-settings', 'sants_webhook_settings_page');
}

function sants_webhook_settings_page() {
    ?>
    <div class="wrap">
        <h2>SANTS Webhook Settings</h2>
        <form method="post" action="options.php">
            <?php settings_fields('sants-webhook-settings-group'); ?>
            <?php do_settings_sections('sants-webhook-settings-group'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Pipedrive API Key</th>
                    <td><input type="text" name="pipedrive_api_key" value="<?php echo esc_attr(get_option('pipedrive_api_key')); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Owner ID</th>
                    <td><input type="text" name="owner_id" value="<?php echo esc_attr(get_option('owner_id')); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Organization ID</th>
                    <td><input type="text" name="organization_id" value="<?php echo esc_attr(get_option('organization_id')); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Unbounce Secret Token</th>
                    <td><input type="text" name="secret_token" value="<?php echo esc_attr(get_option('secret_token')); ?>" /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

add_action('admin_init', 'sants_webhook_settings_init');

function sants_webhook_settings_init() {
    register_setting('sants-webhook-settings-group', 'pipedrive_api_key');
    register_setting('sants-webhook-settings-group', 'owner_id');
    register_setting('sants-webhook-settings-group', 'organization_id');
    register_setting('sants-webhook-settings-group', 'secret_token');
}

add_action('rest_api_init', function () {
    register_rest_route('sants-webhooks/v1', '/listener/', array(
        'methods' => 'POST',
        'callback' => 'sants_handle_webhook',
        'permission_callback' => 'sants_webhooks_permissions_check'
    ));
});

function sants_handle_webhook($request) {
    // Retrieve Pipedrive credentials and settings from options
    $pipedrive_api_key = get_option('pipedrive_api_key');
    $owner_id = (int)get_option('owner_id');
    $organization_id = (int)get_option('organization_id');

    // Validate `owner_id`
    if ($owner_id <= 0) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Invalid owner_id specified. Please provide a valid positive number.'
        ), 400);
    }

    // Ensure `organization_id` is valid or set to null
    $organization_id = $organization_id > 0 ? $organization_id : null;

    // Get request parameters
    $parameters = $request->get_json_params();
    if (empty($parameters)) {
        $parameters = $request->get_body_params();
    }

    // Extract Unbounce JSON payload
    if (isset($parameters['data_json'])) {
        $decoded_data = json_decode($parameters['data_json'], true);
        $parameters = array_merge($parameters, $decoded_data);
    }

    // Logging (optional)
    if (WP_DEBUG_LOG) {
        error_log('Webhook received: ' . print_r($parameters, true));
    }

    // Extract data fields from the webhook
    $email = !empty($parameters['email']) ? $parameters['email'] : '';
    $firstName = !empty($parameters['first_name']) ? $parameters['first_name'] : '';
    $lastName = !empty($parameters['last_name']) ? $parameters['last_name'] : '';
    $highestQualification = isset($parameters['highest_qualification']) ? $parameters['highest_qualification'] : 'Not provided';
    $callback = isset($parameters['callback']) ? $parameters['callback'] : 'Not provided';
    $productOfInterest = isset($parameters['product_of_interest']) ? $parameters['product_of_interest'] : 'Not provided';

    // First, find or create a person in Pipedrive
    $person_data = [
        "name" => $firstName . " " . $lastName,
        "email" => $email
    ];

    // Search for an existing person by email
    $search_url = 'https://api.pipedrive.com/v1/persons/search?term=' . urlencode($email) . '&api_token=' . $pipedrive_api_key;
    $search_response = file_get_contents($search_url);
    $search_result = json_decode($search_response, true);

    $person_id = null;
    if ($search_result['success'] && !empty($search_result['data']['items'])) {
        $person_id = $search_result['data']['items'][0]['item']['id'];
    } else {
        // Create a new person if not found
        $person_url = 'https://api.pipedrive.com/v1/persons?api_token=' . $pipedrive_api_key;

        $person_headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init($person_url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $person_headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($person_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $person_response = curl_exec($ch);
        $person_httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (WP_DEBUG_LOG) {
            error_log('Person creation response: ' . $person_response);
            error_log('HTTP Status Code (Person): ' . $person_httpStatusCode);
        }

        $person_result = json_decode($person_response, true);
        if ($person_result['success']) {
            $person_id = $person_result['data']['id'];
        } else {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Unable to create or find a person in Pipedrive.'
            ), 400);
        }
    }

    // Prepare Pipedrive request data
    $lead_data = [
        "title" => "Lead: " . $firstName . " " . $lastName,
        "owner_id" => $owner_id,
        "organization_id" => $organization_id,
        "person_id" => $person_id,
        "visible_to" => "3",
        "was_seen" => false
    ];

    // Logging the data payload (optional)
    if (WP_DEBUG_LOG) {
        error_log('Payload to Pipedrive: ' . print_r($lead_data, true));
    }

    // Send a POST request to Pipedrive API
    $url = 'https://api.pipedrive.com/v1/leads?api_token=' . $pipedrive_api_key;

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($lead_data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (WP_DEBUG_LOG) {
        error_log('Pipedrive response: ' . $response);
        error_log('HTTP Status Code: ' . $httpStatusCode);
    }

    // Convert callback value to a more readable format
    $callback_readable = $callback === 'Yes' ? 'Requested' : 'Not Requested';

    // Compose confirmation email using HTML
    $body = "<html><body>";
    $body .= "<h2>A new lead has been received and processed:</h2>";
    $body .= "<p><strong>Page URL:</strong> " . (isset($parameters['page_url']) ? $parameters['page_url'] : 'Not Provided') . "</p>";
    $body .= "<p><strong>Email:</strong> " . $email . "</p>";
    $body .= "<p><strong>First Name:</strong> " . $firstName . "</p>";
    $body .= "<p><strong>Last Name:</strong> " . $lastName . "</p>";
    $body .= "<p><strong>Highest Qualification:</strong> " . $highestQualification . "</p>";
    $body .= "<p><strong>Callback Request:</strong> " . $callback_readable . "</p>";
    $body .= "<p><strong>Product of Interest:</strong> " . $productOfInterest . "</p>";
    $body .= "<p><strong>Variant:</strong> " . (isset($parameters['variant']) ? $parameters['variant'] : 'Not Provided') . "</p>";
    $body .= "<p><strong>IP Address:</strong> " . (isset($parameters['ip_address']) ? $parameters['ip_address'] : 'Not Provided') . "</p>";
    $body .= "<p><strong>Page Name:</strong> " . (isset($parameters['page_name']) ? $parameters['page_name'] : 'Not Provided') . "</p>";
    $body .= "<p><strong>Page UUID:</strong> " . (isset($parameters['page_uuid']) ? $parameters['page_uuid'] : 'Not Provided') . "</p>";
    $body .= "<p><strong>Date Submitted:</strong> " . (isset($parameters['date_submitted']) ? $parameters['date_submitted'] : 'Not Provided') . "</p>";
    $body .= "<p><strong>Time Submitted:</strong> " . (isset($parameters['time_submitted']) ? $parameters['time_submitted'] : 'Not Provided') . "</p>";
    $body .= "<h3>Pipedrive Response:</h3><pre>" . $response . "</pre>";
    $body .= "<p><strong>HTTP Status Code:</strong> " . $httpStatusCode . "</p>";
    $body .= "</body></html>";

    // Set content-type header for HTML email
    $headers = array('Content-Type: text/html; charset=UTF-8');

    // Send confirmation email to the admin
    $to = 'bester.dries@gmail.com';
    $subject = 'Lead Received and Processed';
    wp_mail($to, $subject, $body, $headers);

    return new WP_REST_Response(array(
        'success' => true,
        'message' => 'Webhook received and processed, email sent, and data forwarded to Pipedrive.',
    ), 200);
}

function sants_webhooks_permissions_check($request) {
    $provided_token = $request->get_header('X-Secret-Token');
    $expected_token = get_option('secret_token');

    if ($provided_token !== $expected_token) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Unauthorized access',
        ), 403);
    }
    return true;
}

<?php
/*
Plugin Name: CF7 to API with Auth
Description: Sends Contact Form 7 submissions to an external API with options for Basic Auth or Bearer Auth, Input type, HTTP method, and JSON template.
Version: 8.1
Author: shubham ralli
*/

// Add custom fields to CF7 form editor
add_action('wpcf7_editor_panels', 'cf7_api_integration_panel');
function cf7_api_integration_panel($panels)
{
    $panels['api-settings-panel'] = [
        'title' => __('API Settings', 'cf7-api'),
        'callback' => 'cf7_api_integration_panel_content'
    ];
    return $panels;
}

function cf7_api_integration_panel_content($post)
{
    $api_url = get_post_meta($post->id(), '_cf7_api_url', true);
    $auth_type = get_post_meta($post->id(), '_cf7_auth_type', true);
    $auth_key = get_post_meta($post->id(), '_cf7_auth_key', true);
    $method = get_post_meta($post->id(), '_cf7_method', true);
    $json_template = get_post_meta($post->id(), '_cf7_json_template', true);
    $mail_tags = get_mail_tags($post);

?>
    <h2><?php echo esc_html(__('API Settings', 'cf7-api')); ?></h2>
    <p>
        <label for="cf7-api-url"><?php _e('API URL:', 'cf7-api'); ?></label>
        <input type="text" id="cf7-api-url" name="cf7_api_url" value="<?php echo esc_attr($api_url); ?>" size="80" />
    </p>
    <p>
        <label for="cf7-auth-type"><?php _e('Auth Type:', 'cf7-api'); ?></label>
        <select id="cf7-auth-type" name="cf7_auth_type">
            <option value="basic" <?php selected($auth_type, 'basic'); ?>>Basic Auth</option>
            <option value="bearer" <?php selected($auth_type, 'bearer'); ?>>Bearer Token</option>
        </select>
    </p>
    <p>
        <label for="cf7-auth-key"><?php _e('Auth Key/Token:', 'cf7-api'); ?></label>
        <input type="text" id="cf7-auth-key" name="cf7_auth_key" value="<?php echo esc_attr($auth_key); ?>" size="80" />
    </p>
    <p>
        <label for="cf7-method"><?php _e('HTTP Method:', 'cf7-api'); ?></label>
        <select id="cf7-method" name="cf7_method">
            <option value="POST" <?php selected($method, 'POST'); ?>>POST</option>
            <option value="GET" <?php selected($method, 'GET'); ?>>GET</option>
        </select>
    </p>
    <legend>
        <?php foreach ($mail_tags as $mail_tag) : ?>
            <?php if ($mail_tag['type'] === 'checkbox') : ?>
                <?php foreach ($mail_tag['values'] as $checkbox_row) : ?>
                    <span class="xml_mailtag mailtag code">[<?php echo $mail_tag['name']; ?>-<?php echo $checkbox_row; ?>]</span>
                <?php endforeach; ?>
            <?php else : ?>
                <span class="xml_mailtag mailtag code">[<?php echo $mail_tag['name']; ?>]</span>
            <?php endif; ?>
        <?php endforeach; ?>
    </legend>
    <p>
        <label for="cf7-json-template"><?php _e('JSON Template:', 'cf7-api'); ?></label>
        <textarea id="cf7-json-template" name="cf7_json_template" rows="10" cols="80"><?php echo esc_textarea($json_template); ?></textarea>
    </p>


<?php
}

// Save the settings
add_action('wpcf7_save_contact_form', 'cf7_api_save_integration_settings');
function cf7_api_save_integration_settings($post)
{
    update_post_meta($post->id(), '_cf7_api_url', sanitize_text_field($_POST['cf7_api_url']));
    update_post_meta($post->id(), '_cf7_auth_type', sanitize_text_field($_POST['cf7_auth_type']));
    update_post_meta($post->id(), '_cf7_auth_key', sanitize_text_field($_POST['cf7_auth_key']));
    update_post_meta($post->id(), '_cf7_method', sanitize_text_field($_POST['cf7_method']));
    update_post_meta($post->id(), '_cf7_json_template', wp_kses_post($_POST['cf7_json_template']));
}

// Hook into Contact Form 7 submission
add_action('wpcf7_mail_sent', 'send_cf7_data_to_api');
function send_cf7_data_to_api($contact_form)
{
    $post_id = $contact_form->id();

    // Get API settings
    $api_url = get_post_meta($post_id, '_cf7_api_url', true);
    $auth_type = get_post_meta($post_id, '_cf7_auth_type', true);
    $auth_key = get_post_meta($post_id, '_cf7_auth_key', true);
    $method = get_post_meta($post_id, '_cf7_method', true);
    $json_template = get_post_meta($post_id, '_cf7_json_template', true);

    if (!$api_url) {
        return;
    }

    // Get form data
    $submission = WPCF7_Submission::get_instance();
    if ($submission) {
        $data = $submission->get_posted_data();

        // Replace placeholders in JSON Template with actual form values
        $json_data = replace_placeholders($json_template, $data);

        if ($json_data === null) {
            error_log('Invalid JSON template.');
            return;
        }

        $headers = [
            'Content-Type' => 'application/json',
        ];

        // Set the correct Authorization header based on the selected auth type
        if ($auth_type === 'basic') {
            $headers['Authorization'] = 'Basic ' . base64_encode($auth_key);
        } else {
            $headers['Authorization'] = 'Bearer ' . $auth_key;
        }

        $args = [
            'method'  => $method,
            'headers' => $headers,
            'body'    => json_encode($json_data),
        ];

        // Send request
        $response = wp_remote_request($api_url, $args);

        // Log response or errors
        if (is_wp_error($response)) {
            error_log('API request failed: ' . $response->get_error_message());
        } else {
            error_log('API request successful: ' . wp_remote_retrieve_body($response));
        }
    }
}

function replace_placeholders($template, $data)
{
    // Decode JSON template string into an array
    $json_data = json_decode($template, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return null; // Return null if JSON is invalid
    }

    // Iterate over the JSON data and replace placeholders
    foreach ($json_data['fields'] as &$field) {
        if (isset($field['value']) && is_string($field['value'])) {
            // Replace placeholders with actual form values
            $field['value'] = str_replace(
                array_map(fn($key) => "[$key]", array_keys($data)), // Convert form field names to placeholders
                array_values($data), // Replace with actual form values
                $field['value']
            );
        }
    }

    return $json_data;
}

function get_mail_tags($post)
{
    // Retrieve the mail tags from the post
    $tags = apply_filters('qs_cf7_collect_mail_tags', $post->scan_form_tags());
    $mailtags = [];

    foreach ((array) $tags as $tag) {
        $type = trim($tag['type'], ' *');
        if (!empty($type) && !empty($tag['name'])) {
            $mailtags[] = $tag;
        }
    }

    return $mailtags;
}

function is_acceptance_field($field_name, $post)
{
    $field = $post->scan_form_tags([
        'type' => 'acceptance',
        'name' => $field_name,
    ]);

    return count($field) == 1;
}

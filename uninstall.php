<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;
$submissions_table = $wpdb->prefix . 'forms_approvals';
$sessions_table = $wpdb->prefix . 'forms_approval_sessions';
$wpdb->query("DROP TABLE IF EXISTS $submissions_table");
$wpdb->query("DROP TABLE IF EXISTS $sessions_table");

delete_option('forms_approval_forms');
delete_option('forms_approval_buttons');
delete_option('forms_approval_telegram');

$transients = $wpdb->get_col("SELECT option_name FROM $wpdb->options WHERE option_name LIKE '_transient_forms_approval_%'");
foreach ($transients as $transient) {
    delete_transient(str_replace('_transient_', '', $transient));
}
?>
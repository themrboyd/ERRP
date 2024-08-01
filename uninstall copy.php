<?php
if (!defined('WP_UNINSTALL_PLUGIN') || !WP_UNINSTALL_PLUGIN || dirname(WP_UNINSTALL_PLUGIN) != dirname(plugin_basename(__FILE__))) {
    status_header(404);
    exit;
}
$option_names = array(
    'errp_headline', 'errp_limit', 'errp_display_type', 'errp_layout',
    'errp_show_image', 'errp_show_excerpt', 'errp_excerpt_length', 'errp_cache_time'
);

foreach ($option_names as $option) {
    delete_option($option);
}

// ลบ transients
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_errp_%' OR option_name LIKE '_transient_timeout_errp_%'");

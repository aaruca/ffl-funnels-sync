<?php
/**
 * Removes stored data when the plugin is uninstalled.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('ffl_funnels_sync_options');
delete_option('ffl_fs_schema_version');

if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('ffl_funnels_sync_dispatch', [], 'ffl-funnels-sync');
}

if (function_exists('wp_clear_scheduled_hook')) {
    wp_clear_scheduled_hook('ffl_funnels_sync_dispatch');
}

global $wpdb;

$meta_keys = [
    '_ffl_fs_sync_sent',
    '_ffl_fs_sync_attempts',
    '_ffl_fs_sync_last_error',
    '_ffl_fs_fbp',
    '_ffl_fs_fbc',
];

$placeholders = implode(', ', array_fill(0, count($meta_keys), '%s'));

$tables = array_filter(
    [
        $wpdb->postmeta,
        $wpdb->prefix . 'wc_orders_meta',
    ]
);

foreach ($tables as $table) {
    $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($table_exists !== $table) {
        continue;
    }

    // Table name comes from $wpdb, placeholders are static `%s` tokens only.
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $sql = $wpdb->prepare(
        "DELETE FROM `{$table}` WHERE meta_key IN ({$placeholders})",
        ...$meta_keys
    );

    if (is_string($sql)) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($sql);
    }
}

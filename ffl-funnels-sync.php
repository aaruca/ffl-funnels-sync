<?php
/**
 * Plugin Name:       FFL Funnels Sync
 * Plugin URI:        https://github.com/aaruca/ffl-funnels-sync
 * Description:       Sends WooCommerce order lifecycle events to the FFL dashboard over a signed HTTPS webhook with background retries.
 * Version:           1.0.0
 * Author:            FFL Funnels
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ffl-funnels-sync
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   9.4
 */

declare(strict_types=1);

namespace FFL\FunnelsSync;

defined('ABSPATH') || exit;

define('FFL_FS_VERSION', '1.0.0');
define('FFL_FS_FILE', __FILE__);
define('FFL_FS_DIR', plugin_dir_path(__FILE__));

spl_autoload_register(
    static function (string $class): void {
        $prefix = __NAMESPACE__ . '\\';
        if (strpos($class, $prefix) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $file     = FFL_FS_DIR . 'includes/' . str_replace('\\', '/', $relative) . '.php';

        if (is_readable($file)) {
            require_once $file;
        }
    }
);

add_action(
    'before_woocommerce_init',
    static function (): void {
        if (!class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            return;
        }

        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            FFL_FS_FILE,
            true
        );
    }
);

add_action(
    'plugins_loaded',
    static function (): void {
        if (!class_exists('WooCommerce')) {
            add_action(
                'admin_notices',
                static function (): void {
                    printf(
                        '<div class="notice notice-error"><p>%s</p></div>',
                        esc_html__('FFL Funnels Sync requires WooCommerce to be active.', 'ffl-funnels-sync')
                    );
                }
            );

            return;
        }

        Plugin::instance()->boot();
    },
    20
);

register_activation_hook(FFL_FS_FILE, [Plugin::class, 'activate']);
register_deactivation_hook(FFL_FS_FILE, [Plugin::class, 'deactivate']);

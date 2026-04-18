<?php
declare(strict_types=1);

namespace FFL\FunnelsSync;

defined('ABSPATH') || exit;

final class Settings
{
    private const PAGE_SLUG = 'ffl-funnels-sync';

    private static function secret_managed_by_constant(): bool
    {
        return (defined('FFL_FUNNELS_SYNC_SECRET') && (string) \constant('FFL_FUNNELS_SYNC_SECRET') !== '')
            || (defined('FFL_WEBHOOK_SECRET') && (string) \constant('FFL_WEBHOOK_SECRET') !== '');
    }

    private static function stored_secret_exists(array $opts): bool
    {
        return !self::secret_managed_by_constant() && trim((string) ($opts['secret'] ?? '')) !== '';
    }

    private static function stored_endpoint_exists(array $opts): bool
    {
        return trim((string) ($opts['endpoint'] ?? '')) !== '';
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_ffl_fs_connection_test', [$this, 'handle_connection_test']);
        add_filter('plugin_action_links_' . plugin_basename(FFL_FS_FILE), [$this, 'action_links']);
    }

    public function add_menu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('FFL Funnels Sync', 'ffl-funnels-sync'),
            __('FFL Funnels Sync', 'ffl-funnels-sync'),
            'manage_woocommerce',
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    public function register_settings(): void
    {
        register_setting(
            self::PAGE_SLUG,
            Plugin::OPT_KEY,
            [
                'type'              => 'array',
                'sanitize_callback' => [$this, 'sanitize'],
                'default'           => [],
            ]
        );
    }

    /**
     * @param mixed $input
     *
     * @return array<string, mixed>
     */
    public function sanitize($input): array
    {
        $existing = (array) get_option(Plugin::OPT_KEY, []);

        if (!current_user_can('manage_woocommerce')) {
            return $existing;
        }

        $input = is_array($input) ? $input : [];

        $endpoint = isset($input['endpoint']) ? esc_url_raw(trim((string) $input['endpoint'])) : '';
        if ($endpoint !== '' && stripos($endpoint, 'https://') !== 0) {
            add_settings_error(
                self::PAGE_SLUG,
                'ffl_fs_endpoint',
                __('Webhook URL must use HTTPS.', 'ffl-funnels-sync')
            );
            $endpoint = (string) ($existing['endpoint'] ?? '');
        }

        $secret_input = isset($input['secret']) ? trim((string) $input['secret']) : '';
        $secret       = (string) ($existing['secret'] ?? '');

        if ($secret_input !== '') {
            try {
                $secret = Crypto::encrypt_secret($secret_input);
            } catch (\Throwable $e) {
                add_settings_error(
                    self::PAGE_SLUG,
                    'ffl_fs_crypto',
                    __('Could not encrypt the shared secret. Check that OpenSSL is enabled and WordPress security keys are set.', 'ffl-funnels-sync')
                );
            }
        }

        return [
            'endpoint' => $endpoint,
            'secret'   => $secret,
            'debug'    => !empty($input['debug']),
        ];
    }

    /**
     * @param array<int, string> $links
     *
     * @return array<int, string>
     */
    public function action_links(array $links): array
    {
        $url = admin_url('admin.php?page=' . self::PAGE_SLUG);
        array_unshift(
            $links,
            sprintf(
                '<a href="%s">%s</a>',
                esc_url($url),
                esc_html__('Settings', 'ffl-funnels-sync')
            )
        );

        return $links;
    }

    public function handle_connection_test(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to run this action.', 'ffl-funnels-sync'));
        }

        check_admin_referer('ffl_fs_connection_test');

        $tkey = $this->connection_test_transient_key();

        try {
            (new Dispatcher())->send(Dispatcher::connection_test_payload());
            set_transient(
                $tkey,
                [
                    'ok'  => true,
                    'msg' => __('The webhook responded with HTTP 2xx using the current URL, secret, and signature headers.', 'ffl-funnels-sync'),
                ],
                120
            );
        } catch (\Throwable $e) {
            set_transient(
                $tkey,
                [
                    'ok'  => false,
                    'msg' => sprintf(
                        /* translators: %s: error message from the HTTP client or Dispatcher. */
                        __('Connection test failed: %s', 'ffl-funnels-sync'),
                        wp_strip_all_tags($e->getMessage())
                    ),
                ],
                120
            );
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
        exit;
    }

    /**
     * One-shot notice after the connection test redirect (admin_post).
     */
    private function connection_test_transient_key(): string
    {
        return 'ffl_fs_conn_test_' . (string) get_current_user_id();
    }

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $opts          = (array) get_option(Plugin::OPT_KEY, []);
        $secret_locked = self::secret_managed_by_constant();
        $has_endpoint  = self::stored_endpoint_exists($opts);
        $has_secret    = self::stored_secret_exists($opts);
        $configured    = Plugin::is_configured();

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('FFL Funnels Sync', 'ffl-funnels-sync'); ?></h1>

            <?php settings_errors(self::PAGE_SLUG); ?>

            <?php
            $conn = get_transient($this->connection_test_transient_key());
            if (is_array($conn)) {
                delete_transient($this->connection_test_transient_key());
                $class = !empty($conn['ok']) ? 'notice-success' : 'notice-error';
                $msg   = isset($conn['msg']) ? (string) $conn['msg'] : '';
                if ($msg !== '') {
                    printf(
                        '<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
                        esc_attr($class),
                        esc_html($msg)
                    );
                }
            }
            ?>

            <?php if (!$configured) : ?>
                <div class="notice notice-warning">
                    <p>
                        <?php esc_html_e(
                            'Set a webhook URL and shared secret (or define FFL_FUNNELS_SYNC_ENDPOINT / FFL_FUNNELS_SYNC_SECRET in wp-config.php) before orders can sync.',
                            'ffl-funnels-sync'
                        ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields(self::PAGE_SLUG); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="ffl_fs_endpoint"><?php esc_html_e('Webhook URL', 'ffl-funnels-sync'); ?></label>
                        </th>
                        <td>
                            <input
                                type="url"
                                id="ffl_fs_endpoint"
                                class="regular-text code"
                                name="<?php echo esc_attr(Plugin::OPT_KEY); ?>[endpoint]"
                                value="<?php echo esc_attr((string) ($opts['endpoint'] ?? '')); ?>"
                                placeholder="<?php echo esc_attr(
                                    $has_endpoint
                                        ? ''
                                        : __('Paste the webhook URL from your dashboard', 'ffl-funnels-sync')
                                ); ?>"
                                required
                            />
                            <p class="description">
                                <?php esc_html_e(
                                    'Enter the HTTPS webhook URL for this site. Optional: define FFL_FUNNELS_SYNC_ENDPOINT or FFL_WEBHOOK_URL in wp-config.php to manage it outside the database.',
                                    'ffl-funnels-sync'
                                ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ffl_fs_secret"><?php esc_html_e('Shared secret', 'ffl-funnels-sync'); ?></label>
                        </th>
                        <td>
                            <input
                                type="password"
                                id="ffl_fs_secret"
                                class="regular-text"
                                name="<?php echo esc_attr(Plugin::OPT_KEY); ?>[secret]"
                                value=""
                                autocomplete="new-password"
                                placeholder="<?php echo esc_attr(
                                    $secret_locked
                                        ? __('Managed in wp-config.php', 'ffl-funnels-sync')
                                        : ($has_secret
                                            ? __('Leave blank to keep the current secret', 'ffl-funnels-sync')
                                            : __('Paste the shared secret from your dashboard', 'ffl-funnels-sync'))
                                ); ?>"
                                <?php disabled($secret_locked); ?>
                            />
                            <p class="description">
                                <?php esc_html_e(
                                    'Used for HMAC signing. Enter the shared secret provided for this site; it is stored encrypted in the database. Optional: define FFL_FUNNELS_SYNC_SECRET or FFL_WEBHOOK_SECRET in wp-config.php to manage the secret outside the database.',
                                    'ffl-funnels-sync'
                                ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Logging', 'ffl-funnels-sync'); ?></th>
                        <td>
                            <label>
                                <input
                                    type="checkbox"
                                    name="<?php echo esc_attr(Plugin::OPT_KEY); ?>[debug]"
                                    value="1"
                                    <?php checked(!empty($opts['debug'])); ?>
                                />
                                <?php esc_html_e('Log sync errors to the PHP error log.', 'ffl-funnels-sync'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr />

            <h2><?php esc_html_e('Connection test', 'ffl-funnels-sync'); ?></h2>
            <p class="description">
                <?php esc_html_e(
                    'Sends a signed JSON payload (event connection_test) with the same headers as real order webhooks. Save settings first if you changed URL or secret.',
                    'ffl-funnels-sync'
                ); ?>
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('ffl_fs_connection_test'); ?>
                <input type="hidden" name="action" value="ffl_fs_connection_test" />
                <?php
                submit_button(
                    __('Test connection now', 'ffl-funnels-sync'),
                    'secondary',
                    'submit',
                    false,
                    Plugin::is_configured() ? [] : ['disabled' => true]
                );
                ?>
            </form>
            <?php if (!Plugin::is_configured()) : ?>
                <p class="description">
                    <?php esc_html_e('Configure webhook URL and shared secret above (or wp-config constants) before testing.', 'ffl-funnels-sync'); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }
}

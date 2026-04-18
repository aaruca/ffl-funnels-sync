<?php
declare(strict_types=1);

namespace FFL\FunnelsSync;

defined('ABSPATH') || exit;

final class Plugin
{
    public const OPT_KEY = 'ffl_funnels_sync_options';

    // Internal schema revision. Bump when the stored option shape or crypto layout changes.
    public const SCHEMA_OPTION = 'ffl_fs_schema_version';
    private const SCHEMA_REVISION = 4;

    public const ACTION_DISPATCH = 'ffl_funnels_sync_dispatch';

    public const AS_GROUP = 'ffl-funnels-sync';

    public const META_SENT = '_ffl_fs_sync_sent';

    public const META_ATTEMPTS = '_ffl_fs_sync_attempts';

    public const META_LAST_ERROR = '_ffl_fs_sync_last_error';

    public const META_FBP = '_ffl_fs_fbp';

    public const META_FBC = '_ffl_fs_fbc';

    public const MAX_ATTEMPTS = 5;

    private static ?self $instance = null;

    /** @var array<string, true> */
    private array $pending_shutdown_dispatches = [];

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    public function boot(): void
    {
        if (is_admin()) {
            (new Settings())->register();
        }

        add_action('woocommerce_checkout_create_order', [$this, 'capture_attribution'], 10, 1);
        add_action('woocommerce_payment_complete', [$this, 'enqueue_paid_dispatch'], 20, 1);
        add_action('woocommerce_order_status_completed', [$this, 'enqueue_completed_dispatch'], 20, 1);
        add_action('woocommerce_order_refunded', [$this, 'enqueue_refund_dispatch'], 20, 2);

        add_action(self::ACTION_DISPATCH, [$this, 'dispatch_async_job'], 10, 4);
        add_action('init', [self::class, 'migrate_plaintext_secret'], 1);
    }

    /**
     * Encrypts any plaintext secret still sitting in wp_options.
     */
    public static function migrate_plaintext_secret(): void
    {
        $opts = get_option(self::OPT_KEY);
        if (!is_array($opts)) {
            return;
        }

        $secret = isset($opts['secret']) ? (string) $opts['secret'] : '';
        if ($secret === '' || Crypto::is_encrypted($secret)) {
            return;
        }

        try {
            $opts['secret'] = Crypto::encrypt_secret($secret);
            update_option(self::OPT_KEY, $opts, true);
        } catch (\Throwable $e) {
            Logger::error($e->getMessage());
        }
    }

    public function capture_attribution(\WC_Order $order): void
    {
        $fbp = isset($_COOKIE['_fbp'])
            ? sanitize_text_field(wp_unslash((string) $_COOKIE['_fbp']))
            : '';

        $fbc = isset($_COOKIE['_fbc'])
            ? sanitize_text_field(wp_unslash((string) $_COOKIE['_fbc']))
            : '';

        if ($fbc === '' && isset($_GET['fbclid'])) {
            $fbclid = sanitize_text_field(wp_unslash((string) $_GET['fbclid']));
            if ($fbclid !== '') {
                $fbc = sprintf('fb.1.%d.%s', (int) (microtime(true) * 1000), $fbclid);
            }
        }

        if ($fbp !== '') {
            $order->update_meta_data(self::META_FBP, $fbp);
        }

        if ($fbc !== '') {
            $order->update_meta_data(self::META_FBC, $fbc);
        }
    }

    /**
     * @param mixed $order_id
     */
    public function enqueue_paid_dispatch($order_id): void
    {
        $this->queue_order_event((int) $order_id, 'paid', 'order_paid');
    }

    /**
     * @param mixed $order_id
     */
    public function enqueue_completed_dispatch($order_id): void
    {
        $this->queue_order_event((int) $order_id, 'completed', 'order_completed');
    }

    /**
     * @param mixed $order_id
     * @param mixed $refund_id
     */
    public function enqueue_refund_dispatch($order_id, $refund_id): void
    {
        $order_id  = absint((int) $order_id);
        $refund_id = absint((int) $refund_id);

        if ($order_id === 0 || $refund_id === 0) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return;
        }

        $refund = wc_get_order($refund_id);
        if (!$refund instanceof \WC_Order_Refund) {
            return;
        }

        $event_key = 'refund_' . $refund_id;
        $event     = $this->is_fully_refunded($order)
            ? 'order_fully_refunded'
            : 'order_partially_refunded';

        $this->queue_order_event($order_id, $event_key, $event, $refund_id);
    }

    /**
     * @param mixed $order_id
     * @param mixed $event_key
     * @param mixed $event_name
     * @param mixed $refund_id
     */
    public function dispatch_async_job($order_id, $event_key = '', $event_name = '', $refund_id = 0): void
    {
        $order_id   = absint((int) $order_id);
        $event_key  = sanitize_key((string) $event_key);
        $event_name = sanitize_key((string) $event_name);
        $refund_id  = absint((int) $refund_id);

        if ($order_id === 0 || $event_key === '' || $event_name === '') {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return;
        }

        if ($this->already_synced($order, $event_key, $event_name, $refund_id)) {
            return;
        }

        try {
            $this->send_order_payload($order_id, $event_key, $event_name, $refund_id);
        } catch (\Throwable $e) {
            $this->handle_dispatch_failure($order, $event_key, $event_name, $refund_id, $e);
        }
    }

    public static function is_configured(): bool
    {
        return Dispatcher::get_endpoint() !== '' && Dispatcher::get_shared_secret() !== '';
    }

    public static function activate(): void
    {
        $defaults = self::default_options();
        $stored   = get_option(self::OPT_KEY, false);
        $opts     = is_array($stored) ? $stored : [];

        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $opts)) {
                $opts[$key] = $value;
            }
        }

        $opts = self::encrypt_secret_option_if_needed($opts);

        update_option(self::OPT_KEY, $opts, true);

        if (self::schema_revision() < self::SCHEMA_REVISION) {
            self::persist_schema_revision(self::SCHEMA_REVISION);
        }
    }

    public static function deactivate(): void
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::ACTION_DISPATCH, [], self::AS_GROUP);
        }

        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(self::ACTION_DISPATCH);
        }
    }

    /**
     * @return array{endpoint: string, secret: string, debug: bool}
     */
    private static function default_options(): array
    {
        return [
            'endpoint' => (string) apply_filters('ffl_fs_default_endpoint', ''),
            'secret'   => '',
            'debug'    => false,
        ];
    }

    /**
     * @param array<string, mixed> $opts
     *
     * @return array<string, mixed>
     */
    private static function encrypt_secret_option_if_needed(array $opts): array
    {
        $secret = isset($opts['secret']) ? (string) $opts['secret'] : '';
        if ($secret === '' || Crypto::is_encrypted($secret)) {
            return $opts;
        }

        try {
            $opts['secret'] = Crypto::encrypt_secret($secret);
        } catch (\Throwable $e) {
            Logger::error('encrypt on activate: ' . $e->getMessage());
        }

        return $opts;
    }

    private static function schema_revision(): int
    {
        $raw = get_option(self::SCHEMA_OPTION, 0);

        if (is_int($raw)) {
            return max(0, $raw);
        }

        if (is_string($raw) && ctype_digit($raw)) {
            return (int) $raw;
        }

        return 0;
    }

    private static function persist_schema_revision(int $revision): void
    {
        update_option(self::SCHEMA_OPTION, $revision, true);
    }

    private function queue_order_event(int $order_id, string $event_key, string $event_name, int $refund_id = 0): void
    {
        if ($order_id === 0 || $event_key === '' || $event_name === '') {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return;
        }

        if ($this->already_synced($order, $event_key, $event_name, $refund_id)) {
            return;
        }

        if ($this->should_use_action_scheduler()) {
            $this->schedule_dispatch($order_id, $event_key, $event_name, $refund_id);

            return;
        }

        $dispatch_hash = $this->dispatch_hash($order_id, $event_key, $event_name, $refund_id);
        if (isset($this->pending_shutdown_dispatches[$dispatch_hash])) {
            return;
        }

        $this->pending_shutdown_dispatches[$dispatch_hash] = true;

        add_action(
            'shutdown',
            function () use ($order_id, $event_key, $event_name, $refund_id, $dispatch_hash): void {
                unset($this->pending_shutdown_dispatches[$dispatch_hash]);

                $order = wc_get_order($order_id);
                if (!$order instanceof \WC_Order) {
                    return;
                }

                if ($this->already_synced($order, $event_key, $event_name, $refund_id)) {
                    return;
                }

                try {
                    $this->send_order_payload($order_id, $event_key, $event_name, $refund_id);
                } catch (\Throwable $e) {
                    $this->handle_dispatch_failure($order, $event_key, $event_name, $refund_id, $e);
                }
            },
            20
        );
    }

    private function schedule_dispatch(
        int $order_id,
        string $event_key,
        string $event_name,
        int $refund_id = 0,
        ?int $run_at = null,
        bool $dedupe = true
    ): void {
        $args   = [$order_id, $event_key, $event_name, $refund_id];
        $run_at = $run_at ?? time();

        if ($this->should_use_action_scheduler()) {
            if ($dedupe && function_exists('as_has_scheduled_action') && as_has_scheduled_action(self::ACTION_DISPATCH, $args, self::AS_GROUP)) {
                return;
            }

            if ($run_at <= time() && function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action(self::ACTION_DISPATCH, $args, self::AS_GROUP);

                return;
            }

            if (function_exists('as_schedule_single_action')) {
                as_schedule_single_action($run_at, self::ACTION_DISPATCH, $args, self::AS_GROUP);

                return;
            }
        }

        if ($dedupe && wp_next_scheduled(self::ACTION_DISPATCH, $args) !== false) {
            return;
        }

        wp_schedule_single_event($run_at, self::ACTION_DISPATCH, $args);
    }

    private function send_order_payload(int $order_id, string $event_key, string $event_name, int $refund_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return;
        }

        if ($this->already_synced($order, $event_key, $event_name, $refund_id)) {
            return;
        }

        $payload = (new PayloadBuilder())->build($order, $event_name, $event_key, $refund_id);
        (new Dispatcher())->send($payload);

        $this->mark_event_sent($order, $event_key, $event_name, $refund_id);
        $order->save();
    }

    private function handle_dispatch_failure(
        \WC_Order $order,
        string $event_key,
        string $event_name,
        int $refund_id,
        \Throwable $e
    ): void {
        $attempts = $this->increment_attempts($order, $event_key, $e->getMessage());

        Logger::error(
            sprintf(
                'Dispatch failed for order %d event %s (attempt %d): %s',
                (int) $order->get_id(),
                $event_key,
                $attempts,
                $e->getMessage()
            )
        );

        if ($attempts >= self::MAX_ATTEMPTS) {
            return;
        }

        $this->schedule_dispatch(
            (int) $order->get_id(),
            $event_key,
            $event_name,
            $refund_id,
            time() + $this->retry_delay($attempts),
            false
        );
    }

    private function mark_event_sent(\WC_Order $order, string $event_key, string $event_name, int $refund_id): void
    {
        $sent_events              = $this->get_sent_events($order);
        $sent_events[$event_key]  = gmdate(\DATE_ATOM);
        $attempts                 = $this->get_attempts($order);
        $last_errors              = $this->get_last_errors($order);

        unset($attempts[$event_key], $last_errors[$event_key]);

        $order->update_meta_data(self::META_SENT, $sent_events);
        $order->update_meta_data(self::META_ATTEMPTS, $attempts);
        $order->update_meta_data(self::META_LAST_ERROR, $last_errors);

        do_action('ffl_fs_event_sent', $order, $event_key, $event_name, $refund_id);
    }

    private function increment_attempts(\WC_Order $order, string $event_key, string $message): int
    {
        $attempts            = $this->get_attempts($order);
        $last_errors         = $this->get_last_errors($order);
        $attempts[$event_key] = ($attempts[$event_key] ?? 0) + 1;
        $last_errors[$event_key] = wp_strip_all_tags($message);

        $order->update_meta_data(self::META_ATTEMPTS, $attempts);
        $order->update_meta_data(self::META_LAST_ERROR, $last_errors);
        $order->save();

        return $attempts[$event_key];
    }

    /**
     * @return array<string, string>
     */
    private function get_sent_events(\WC_Order $order): array
    {
        $raw = $order->get_meta(self::META_SENT);

        if (!is_array($raw)) {
            return [];
        }

        $events = [];

        foreach ($raw as $event_key => $sent_at) {
            $event_key = sanitize_key((string) $event_key);
            $sent_at   = is_string($sent_at) ? trim($sent_at) : '';

            if ($event_key === '' || $sent_at === '') {
                continue;
            }

            $events[$event_key] = $sent_at;
        }

        return $events;
    }

    /**
     * @return array<string, int>
     */
    private function get_attempts(\WC_Order $order): array
    {
        $raw = $order->get_meta(self::META_ATTEMPTS);

        if (!is_array($raw)) {
            return [];
        }

        $attempts = [];

        foreach ($raw as $event_key => $attempt_count) {
            $event_key = sanitize_key((string) $event_key);

            if ($event_key === '') {
                continue;
            }

            $attempts[$event_key] = max(0, (int) $attempt_count);
        }

        return $attempts;
    }

    /**
     * @return array<string, string>
     */
    private function get_last_errors(\WC_Order $order): array
    {
        $raw = $order->get_meta(self::META_LAST_ERROR);

        if (!is_array($raw)) {
            return [];
        }

        $errors = [];

        foreach ($raw as $event_key => $message) {
            $event_key = sanitize_key((string) $event_key);
            $message   = is_string($message) ? trim($message) : '';

            if ($event_key === '' || $message === '') {
                continue;
            }

            $errors[$event_key] = $message;
        }

        return $errors;
    }

    private function already_synced(\WC_Order $order, string $event_key, string $event_name, int $refund_id): bool
    {
        $sent_events = $this->get_sent_events($order);

        if (!isset($sent_events[$event_key])) {
            return false;
        }

        return !apply_filters('ffl_fs_force_resend', false, $order, $event_key, $event_name, $refund_id);
    }

    private function should_use_action_scheduler(): bool
    {
        return function_exists('as_has_scheduled_action')
            && (function_exists('as_enqueue_async_action') || function_exists('as_schedule_single_action'));
    }

    private function retry_delay(int $attempts): int
    {
        $attempts = max(1, $attempts);

        return min(HOUR_IN_SECONDS, (int) pow(2, $attempts - 1) * MINUTE_IN_SECONDS);
    }

    private function dispatch_hash(int $order_id, string $event_key, string $event_name, int $refund_id): string
    {
        return md5($order_id . '|' . $event_key . '|' . $event_name . '|' . $refund_id);
    }

    private function is_fully_refunded(\WC_Order $order): bool
    {
        return abs((float) $order->get_total_refunded()) + 0.00001 >= (float) $order->get_total();
    }
}

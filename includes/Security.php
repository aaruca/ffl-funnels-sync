<?php
declare(strict_types=1);

namespace FFL\FunnelsSync;

defined('ABSPATH') || exit;

/**
 * Host allowlisting for outbound webhooks and light redaction for logs.
 */
final class Security
{
    /**
     * Optional host allowlist via the `ffl_fs_allowed_webhook_hosts` filter.
     *
     * Default (filter returns null) imposes no extra host restriction on top of
     * the HTTPS + valid-host check. Return an array of hostnames to enforce an
     * exact-match allowlist, e.g.:
     *
     * add_filter('ffl_fs_allowed_webhook_hosts', static function (): array {
     *     return ['ads-dashboard.fflfunnels.com'];
     * });
     */
    public static function is_endpoint_host_allowed(string $endpoint_url, string $host): bool
    {
        $host = strtolower($host);
        if ($host === '') {
            return false;
        }

        /** @var mixed $allowed */
        $allowed = apply_filters('ffl_fs_allowed_webhook_hosts', null, $endpoint_url, $host);

        if ($allowed === null) {
            return true;
        }

        // A misconfigured filter (non-array return) should not lock everyone out.
        if (!is_array($allowed)) {
            return true;
        }

        foreach ($allowed as $item) {
            if (strtolower((string) $item) === $host) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strip tags and redact obvious email addresses before writing to error_log.
     */
    public static function redact_log_message(string $message): string
    {
        $message = wp_strip_all_tags($message);
        $message = preg_replace(
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/',
            '[redacted-email]',
            $message
        );
        if (!is_string($message)) {
            return '';
        }

        if (strlen($message) > 2000) {
            return substr($message, 0, 2000) . '...';
        }

        return $message;
    }
}

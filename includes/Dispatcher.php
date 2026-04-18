<?php
declare(strict_types=1);

namespace FFL\FunnelsSync;

defined('ABSPATH') || exit;

final class Dispatcher
{
    public static function get_shared_secret(): string
    {
        $constant = self::constant_string('FFL_FUNNELS_SYNC_SECRET')
            ?: self::constant_string('FFL_WEBHOOK_SECRET');
        if ($constant !== '') {
            return $constant;
        }

        $opts = (array) get_option(Plugin::OPT_KEY, []);

        return Crypto::decrypt_or_legacy((string) ($opts['secret'] ?? ''));
    }

    public static function get_endpoint(): string
    {
        $constant = trim(
            self::constant_string('FFL_FUNNELS_SYNC_ENDPOINT')
                ?: self::constant_string('FFL_WEBHOOK_URL')
        );
        if ($constant !== '') {
            return $constant;
        }

        $opts = (array) get_option(Plugin::OPT_KEY, []);

        return trim((string) ($opts['endpoint'] ?? ''));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws \RuntimeException
     */
    public function send(array $payload): void
    {
        $endpoint = self::get_endpoint();
        $secret   = self::get_shared_secret();

        if ($endpoint === '' || $secret === '') {
            throw new \RuntimeException('Webhook endpoint or shared secret is missing.');
        }

        if (stripos($endpoint, 'https://') !== 0) {
            throw new \RuntimeException('Webhook endpoint must use HTTPS.');
        }

        $host = wp_parse_url($endpoint, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new \RuntimeException('Webhook URL is missing a host.');
        }

        if (!Security::is_endpoint_host_allowed($endpoint, $host)) {
            throw new \RuntimeException('Webhook host is blocked by ffl_fs_allowed_webhook_hosts.');
        }

        $body = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new \RuntimeException('Failed to encode JSON payload.');
        }

        $timestamp = (string) time();
        $headers   = self::signed_headers($timestamp, $body, $secret);

        $response = wp_remote_post(
            $endpoint,
            [
                'timeout'            => 8,
                'blocking'           => true,
                'redirection'        => 0,
                'reject_unsafe_urls' => true,
                'sslverify'          => true,
                'headers'            => $headers,
                'body'               => $body,
            ]
        );

        if (is_wp_error($response)) {
            throw new \RuntimeException($response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException('Webhook HTTP ' . $code);
        }
    }

    /**
     * @return array<string, string>
     */
    private static function signed_headers(string $timestamp, string $raw_body, string $secret): array
    {
        $signature = hash_hmac('sha256', $timestamp . '.' . $raw_body, $secret);

        return [
            'Content-Type'     => 'application/json; charset=utf-8',
            'X-FFL-Timestamp'  => $timestamp,
            'X-FFL-Signature'  => 'sha256=' . $signature,
            'X-FFL-Plugin'     => 'ffl-funnels-sync/' . FFL_FS_VERSION,
            'User-Agent'       => 'FFLFunnelsSync/' . FFL_FS_VERSION . ' (+' . home_url() . ')',
        ];
    }

    private static function constant_string(string $name): string
    {
        return defined($name) ? (string) \constant($name) : '';
    }
}

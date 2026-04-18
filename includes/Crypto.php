<?php
declare(strict_types=1);

namespace FFL\FunnelsSync;

defined('ABSPATH') || exit;

/**
 * Encrypts the shared webhook secret at rest in wp_options using keys WordPress already defines.
 */
final class Crypto
{
    private const PREFIX = 'ffl1:';

    private const CIPHER = 'aes-256-gcm';

    public static function is_encrypted(string $stored): bool
    {
        return strpos($stored, self::PREFIX) === 0;
    }

    /**
     * Produces a value safe to store in options (prefixed + base64 JSON blob).
     *
     * @throws \RuntimeException when OpenSSL cannot encrypt.
     */
    public static function encrypt_secret(string $plaintext): string
    {
        $plaintext = trim($plaintext);
        if ($plaintext === '') {
            return '';
        }

        self::assert_openssl();

        $key        = self::derive_key();
        $iv_length  = openssl_cipher_iv_length(self::CIPHER);
        if ($iv_length === false || $iv_length < 8) {
            throw new \RuntimeException('Unable to read IV length for cipher.');
        }

        $iv = random_bytes((int) $iv_length);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($ciphertext === false || !is_string($tag) || $tag === '') {
            throw new \RuntimeException('Unable to encrypt secret.');
        }

        $payload = [
            'iv'  => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ct'  => base64_encode($ciphertext),
        ];

        $encoded = wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('Unable to encode encrypted payload.');
        }

        return self::PREFIX . base64_encode($encoded);
    }

    /**
     * Returns plaintext secret for signing. Supports legacy plaintext values until migrated.
     */
    public static function decrypt_or_legacy(string $stored): string
    {
        $stored = trim($stored);
        if ($stored === '') {
            return '';
        }

        if (!self::is_encrypted($stored)) {
            return $stored;
        }

        self::assert_openssl();

        $json = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if (!is_string($json) || $json === '') {
            return '';
        }

        /** @var mixed $payload */
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return '';
        }

        $iv_b  = isset($payload['iv']) ? (string) $payload['iv'] : '';
        $tag_b = isset($payload['tag']) ? (string) $payload['tag'] : '';
        $ct_b  = isset($payload['ct']) ? (string) $payload['ct'] : '';

        $iv  = base64_decode($iv_b, true);
        $tag = base64_decode($tag_b, true);
        $ct  = base64_decode($ct_b, true);

        if (!is_string($iv) || !is_string($tag) || !is_string($ct)) {
            return '';
        }

        $key = self::derive_key();

        $plaintext = openssl_decrypt(
            $ct,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            ''
        );

        return $plaintext === false ? '' : (string) $plaintext;
    }

    private static function assert_openssl(): void
    {
        if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
            throw new \RuntimeException('OpenSSL extension is required to encrypt the webhook secret.');
        }
    }

    /**
     * 32-byte key derived from WordPress secrets (never stored).
     */
    private static function derive_key(): string
    {
        if (!defined('AUTH_KEY') || AUTH_KEY === '') {
            throw new \RuntimeException('AUTH_KEY is not defined; cannot derive encryption key.');
        }

        $material = AUTH_KEY
            . (defined('AUTH_SALT') ? AUTH_SALT : '')
            . (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '')
            . (defined('SECURE_AUTH_SALT') ? SECURE_AUTH_SALT : '');

        return hash_hmac('sha256', 'ffl-funnels-sync/crypto/v1', $material, true);
    }
}

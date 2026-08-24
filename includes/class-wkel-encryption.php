<?php
defined('ABSPATH') || exit;

/**
 * AES-256-CBC encryption/decryption for PII fields.
 *
 * Requires in wp-config.php:
 *   define('WKEL_ENCRYPTION_KEY', '');  // 64-char hex: openssl rand -hex 32
 *   define('WKEL_ENCRYPTION_IV',  '');  // 32-char hex: openssl rand -hex 16
 *
 * If constants are not defined: values are stored unencrypted and an admin
 * notice is shown (see class-wkel-loader.php).
 */
class WKEL_Encryption {

    private const PREFIX = 'wkel_enc:';

    public static function encrypt(string $plaintext): string {
        $key = self::key();
        $iv  = self::iv();

        if ($key === null || $iv === null) {
            return $plaintext;
        }

        $encrypted = openssl_encrypt(
            $plaintext,
            'AES-256-CBC',
            $key,
            0,
            $iv
        );

        if ($encrypted === false) {
            return $plaintext;
        }

        return self::PREFIX . base64_encode($encrypted);
    }

    public static function decrypt(string $ciphertext): string {
        $key = self::key();
        $iv  = self::iv();

        if ($key === null || $iv === null) {
            return $ciphertext;
        }

        if (!self::is_encrypted($ciphertext)) {
            return $ciphertext;
        }

        // Older admin saves could encrypt an already encrypted value. Unwrap
        // a small, bounded number of layers while preserving normal values.
        $value = $ciphertext;
        for ($layer = 0; $layer < 3 && self::is_encrypted($value); $layer++) {
            $decoded = base64_decode(substr($value, strlen(self::PREFIX)));

            if ($decoded === false) {
                return '';
            }

            $decrypted = openssl_decrypt(
                $decoded,
                'AES-256-CBC',
                $key,
                0,
                $iv
            );

            if ($decrypted === false) {
                return '';
            }
            $value = $decrypted;
        }

        return $value;
    }

    public static function is_encrypted(string $value): bool {
        return str_starts_with($value, self::PREFIX);
    }

    private static function key(): ?string {
        return self::hex_constant('WKEL_ENCRYPTION_KEY', 64);
    }

    private static function iv(): ?string {
        if (!defined('WKEL_ENCRYPTION_IV')) {
            return null;
        }

        $value = (string) constant('WKEL_ENCRYPTION_IV');

        // The original production config used an 8-byte IV. OpenSSL padded
        // it with NUL bytes, so preserve that behaviour for existing leads
        // while preventing the warning that was breaking the detail request.
        if (strlen($value) === 16 && ctype_xdigit($value)) {
            $decoded = hex2bin($value);
            return $decoded === false ? null : str_pad($decoded, 16, "\0");
        }

        return self::hex_constant('WKEL_ENCRYPTION_IV', 32);
    }

    private static function hex_constant(string $name, int $expected_length): ?string {
        if (!defined($name)) {
            return null;
        }

        $value = (string) constant($name);

        if (strlen($value) !== $expected_length || !ctype_xdigit($value)) {
            return null;
        }

        $decoded = hex2bin($value);

        return $decoded === false ? null : $decoded;
    }
}

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
        if (!self::keys_defined()) {
            return $plaintext;
        }

        $encrypted = openssl_encrypt(
            $plaintext,
            'AES-256-CBC',
            hex2bin(WKEL_ENCRYPTION_KEY),
            0,
            hex2bin(WKEL_ENCRYPTION_IV)
        );

        if ($encrypted === false) {
            return $plaintext;
        }

        return self::PREFIX . base64_encode($encrypted);
    }

    public static function decrypt(string $ciphertext): string {
        if (!self::keys_defined()) {
            return $ciphertext;
        }

        if (!self::is_encrypted($ciphertext)) {
            return $ciphertext;
        }

        $decoded = base64_decode(substr($ciphertext, strlen(self::PREFIX)));

        if ($decoded === false) {
            return '';
        }

        $decrypted = openssl_decrypt(
            $decoded,
            'AES-256-CBC',
            hex2bin(WKEL_ENCRYPTION_KEY),
            0,
            hex2bin(WKEL_ENCRYPTION_IV)
        );

        return $decrypted !== false ? $decrypted : '';
    }

    public static function is_encrypted(string $value): bool {
        return str_starts_with($value, self::PREFIX);
    }

    private static function keys_defined(): bool {
        return defined('WKEL_ENCRYPTION_KEY') && defined('WKEL_ENCRYPTION_IV');
    }
}

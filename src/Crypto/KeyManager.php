<?php

declare(strict_types=1);

namespace Akvabackup\Crypto;

/**
 * Custody of the single 32-byte AES master key (SecretStream derives per-file subkeys).
 * No sodium dependency — many shared hosts ship PHP without the extension.
 *
 * Stored encrypted at rest in AKVABACKUP_ENC_KEY_ENC via PhpEncryption(_NEW_COOKIE_KEY_).
 * Revealed to the operator ONCE in BO; losing it makes every backup unrecoverable.
 */
final class KeyManager
{
    private const CFG_KEY = 'AKVABACKUP_ENC_KEY_ENC';

    /** Generate once at install; no-op if a key already exists. */
    public static function generate(): void
    {
        if (\Configuration::getGlobalValue(self::CFG_KEY)) {
            return;
        }
        $key = random_bytes(SecretStream::KEY_BYTES);
        $enc = new \PhpEncryption(_NEW_COOKIE_KEY_);
        \Configuration::updateGlobalValue(self::CFG_KEY, $enc->encrypt($key));
    }

    /** Raw 32-byte key. */
    public static function getKey(): string
    {
        $stored = \Configuration::getGlobalValue(self::CFG_KEY);
        if (!$stored) {
            throw new \RuntimeException('KeyManager: encryption key is not set');
        }
        $enc = new \PhpEncryption(_NEW_COOKIE_KEY_);
        $key = $enc->decrypt($stored);
        if (!is_string($key) || strlen($key) !== SecretStream::KEY_BYTES) {
            throw new \RuntimeException('KeyManager: stored encryption key is corrupt');
        }

        return $key;
    }

    /** Hex form for the BO one-time reveal and the downloadable .key file. */
    public static function keyHex(): string
    {
        return bin2hex(self::getKey());
    }
}

<?php

declare(strict_types=1);

namespace Akvabackup\Crypto;

/**
 * Client-side authenticated encryption, chunked AES-256-GCM via openssl (8 MiB chunks).
 * openssl is the ONLY crypto backend: shared-host PHP often ships WITHOUT the sodium
 * extension, while openssl + hash_hkdf are universally available.
 *
 * Construction: per file a random 32-byte salt derives a fresh subkey
 * (HKDF-SHA256(master, salt)), so the 12-byte counter nonce (chunk index, big-endian)
 * can never repeat under a subkey. Each chunk's AAD binds the magic, its index and its
 * final flag — a reordered, truncated, extended or bit-flipped stream fails the GCM tag.
 *
 * On-disk format (must stay byte-compatible with tools/decrypt.php):
 *   [24 bytes] magic 'AKVABACKUP2' padded with NUL to 24 bytes
 *   [32 bytes] random file salt
 *   per chunk:
 *     [4 bytes BE] plaintext length L (<= 8 MiB; a zero-length FINAL chunk is written
 *                  when the plaintext is empty or an exact multiple of the chunk size)
 *     [1 byte]     final flag (0x01 on the last chunk, else 0x00)
 *     [16 bytes]   GCM tag
 *     [L bytes]    ciphertext
 */
final class SecretStream
{
    public const KEY_BYTES = 32;

    private const MAGIC = 'AKVABACKUP2';
    private const MAGIC_LEN = 24;
    private const SALT_LEN = 32;
    private const TAG_LEN = 16;
    private const CHUNK = 8388608; // 8 MiB plaintext per chunk

    public static function encryptFile(string $in, string $out, string $key): void
    {
        self::assertEnv();
        if (strlen($key) !== self::KEY_BYTES) {
            throw new \RuntimeException('SecretStream: invalid key length');
        }

        $fin = @fopen($in, 'rb');
        if ($fin === false) {
            throw new \RuntimeException('SecretStream: cannot open input ' . $in);
        }
        $fout = @fopen($out, 'wb');
        if ($fout === false) {
            fclose($fin);
            throw new \RuntimeException('SecretStream: cannot open output ' . $out);
        }

        try {
            $salt = random_bytes(self::SALT_LEN);
            $subkey = self::subkey($key, $salt);

            self::writeAll($fout, str_pad(self::MAGIC, self::MAGIC_LEN, "\0"));
            self::writeAll($fout, $salt);

            $counter = 0;
            while (true) {
                $chunk = self::readN($fin, self::CHUNK);
                $final = feof($fin);
                $tag = '';
                $cipher = openssl_encrypt(
                    $chunk,
                    'aes-256-gcm',
                    $subkey,
                    OPENSSL_RAW_DATA,
                    self::nonce($counter),
                    $tag,
                    self::aad($counter, $final),
                    self::TAG_LEN
                );
                if ($cipher === false || strlen($tag) !== self::TAG_LEN) {
                    throw new \RuntimeException('SecretStream: openssl encrypt failed');
                }
                self::writeAll($fout, pack('N', strlen($chunk)) . chr($final ? 1 : 0) . $tag . $cipher);
                ++$counter;
                if ($final) {
                    break;
                }
            }
        } finally {
            fclose($fin);
            fclose($fout);
        }
    }

    public static function decryptFile(string $in, string $out, string $key): void
    {
        self::assertEnv();
        if (strlen($key) !== self::KEY_BYTES) {
            throw new \RuntimeException('SecretStream: invalid key length');
        }

        $fin = @fopen($in, 'rb');
        if ($fin === false) {
            throw new \RuntimeException('SecretStream: cannot open input ' . $in);
        }
        $fout = @fopen($out, 'wb');
        if ($fout === false) {
            fclose($fin);
            throw new \RuntimeException('SecretStream: cannot open output ' . $out);
        }

        try {
            $magic = self::readN($fin, self::MAGIC_LEN);
            if ($magic !== str_pad(self::MAGIC, self::MAGIC_LEN, "\0")) {
                throw new \RuntimeException('SecretStream: bad magic (not an akvabackup encrypted file)');
            }
            $salt = self::readN($fin, self::SALT_LEN);
            if (strlen($salt) !== self::SALT_LEN) {
                throw new \RuntimeException('SecretStream: truncated header');
            }
            $subkey = self::subkey($key, $salt);

            $counter = 0;
            while (true) {
                $head = self::readN($fin, 4 + 1 + self::TAG_LEN);
                if (strlen($head) !== 4 + 1 + self::TAG_LEN) {
                    throw new \RuntimeException('SecretStream: unexpected EOF before FINAL chunk');
                }
                $len = (int) unpack('N', substr($head, 0, 4))[1];
                $final = ord($head[4]) === 1;
                $tag = substr($head, 5, self::TAG_LEN);
                if ($len > self::CHUNK) {
                    throw new \RuntimeException('SecretStream: corrupt chunk length');
                }
                $cipher = self::readN($fin, $len);
                if (strlen($cipher) !== $len) {
                    throw new \RuntimeException('SecretStream: truncated chunk');
                }
                $clear = openssl_decrypt(
                    $cipher,
                    'aes-256-gcm',
                    $subkey,
                    OPENSSL_RAW_DATA,
                    self::nonce($counter),
                    $tag,
                    self::aad($counter, $final)
                );
                if ($clear === false) {
                    throw new \RuntimeException('SecretStream: decryption failed (wrong key or tampered file)');
                }
                self::writeAll($fout, $clear);
                ++$counter;
                if ($final) {
                    if (self::readN($fin, 1) !== '') {
                        throw new \RuntimeException('SecretStream: trailing data after FINAL chunk');
                    }
                    break;
                }
            }
        } finally {
            fclose($fin);
            fclose($fout);
        }
    }

    private static function assertEnv(): void
    {
        if (!function_exists('openssl_encrypt') || !in_array('aes-256-gcm', array_map('strtolower', openssl_get_cipher_methods()), true)) {
            throw new \RuntimeException('SecretStream: openssl with aes-256-gcm is required');
        }
    }

    private static function subkey(string $master, string $salt): string
    {
        return hash_hkdf('sha256', $master, self::KEY_BYTES, 'akvabackup-v2-file', $salt);
    }

    /** 12-byte big-endian chunk counter; unique per subkey, and the subkey is unique per file. */
    private static function nonce(int $counter): string
    {
        return str_pad('', 4, "\0") . pack('J', $counter);
    }

    private static function aad(int $counter, bool $final): string
    {
        return self::MAGIC . pack('J', $counter) . chr($final ? 1 : 0);
    }

    /** Read exactly $n bytes unless EOF is reached first (regular-file fread can short-read). */
    private static function readN($fh, int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $r = fread($fh, $n - strlen($buf));
            if ($r === false || $r === '') {
                break;
            }
            $buf .= $r;
        }

        return $buf;
    }

    private static function writeAll($fh, string $data): void
    {
        $len = strlen($data);
        $off = 0;
        while ($off < $len) {
            $w = fwrite($fh, $off === 0 ? $data : substr($data, $off));
            if ($w === false || $w === 0) {
                throw new \RuntimeException('SecretStream: write failed');
            }
            $off += $w;
        }
    }
}

<?php

declare(strict_types=1);

/*
 * akvabackup standalone decryptor. NO PrestaShop bootstrap: runs on any PHP >= 8.1 with
 * the openssl extension (aes-256-gcm) — sodium is deliberately NOT used, the production
 * host ships PHP without it. The chunk logic is duplicated from src/Crypto/SecretStream.php
 * on purpose so a backup can always be recovered without the module or the shop.
 *
 * Usage:  php decrypt.php <hexkey> <infile.enc> <outfile>
 *
 * File format produced by src/Crypto/SecretStream.php ('AKVABACKUP2'):
 *   [24 bytes] magic 'AKVABACKUP2' NUL-padded to 24
 *   [32 bytes] random file salt (per-file subkey = HKDF-SHA256(master, salt))
 *   per chunk: [4B BE plaintext len][1B final flag][16B GCM tag][ciphertext]
 *   nonce = 12-byte BE chunk counter; AAD = magic + counter + final flag.
 */

const AKVABACKUP_MAGIC = 'AKVABACKUP2';
const AKVABACKUP_MAGIC_LEN = 24;
const AKVABACKUP_SALT_LEN = 32;
const AKVABACKUP_TAG_LEN = 16;
const AKVABACKUP_KEY_BYTES = 32;
const AKVABACKUP_CHUNK = 8388608; // 8 MiB

function akvabackup_fail(string $msg): void
{
    fwrite(STDERR, $msg . "\n");
    exit(1);
}

function akvabackup_read_n($fh, int $n): string
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

function akvabackup_write_all($fh, string $data): void
{
    $len = strlen($data);
    $off = 0;
    while ($off < $len) {
        $w = fwrite($fh, $off === 0 ? $data : substr($data, $off));
        if ($w === false || $w === 0) {
            akvabackup_fail('write failed');
        }
        $off += $w;
    }
}

function akvabackup_nonce(int $counter): string
{
    return str_pad('', 4, "\0") . pack('J', $counter);
}

function akvabackup_aad(int $counter, bool $final): string
{
    return AKVABACKUP_MAGIC . pack('J', $counter) . chr($final ? 1 : 0);
}

if ($argc < 4) {
    akvabackup_fail('Usage: php decrypt.php <hexkey> <infile.enc> <outfile>');
}

if (!function_exists('openssl_decrypt')
    || !in_array('aes-256-gcm', array_map('strtolower', openssl_get_cipher_methods()), true)) {
    akvabackup_fail('PHP openssl extension with aes-256-gcm support is required');
}

$hexKey = trim((string) $argv[1]);
$inPath = (string) $argv[2];
$outPath = (string) $argv[3];

$key = @hex2bin($hexKey);
if ($key === false || strlen($key) !== AKVABACKUP_KEY_BYTES) {
    akvabackup_fail('invalid hex key (need ' . AKVABACKUP_KEY_BYTES . ' bytes / ' . (AKVABACKUP_KEY_BYTES * 2) . ' hex chars)');
}

$fin = @fopen($inPath, 'rb');
if ($fin === false) {
    akvabackup_fail('cannot open input ' . $inPath);
}
$fout = @fopen($outPath, 'wb');
if ($fout === false) {
    fclose($fin);
    akvabackup_fail('cannot open output ' . $outPath);
}

try {
    $magic = akvabackup_read_n($fin, AKVABACKUP_MAGIC_LEN);
    if ($magic !== str_pad(AKVABACKUP_MAGIC, AKVABACKUP_MAGIC_LEN, "\0")) {
        akvabackup_fail('bad magic: not an akvabackup encrypted file');
    }

    $salt = akvabackup_read_n($fin, AKVABACKUP_SALT_LEN);
    if (strlen($salt) !== AKVABACKUP_SALT_LEN) {
        akvabackup_fail('truncated header');
    }
    $subkey = hash_hkdf('sha256', $key, AKVABACKUP_KEY_BYTES, 'akvabackup-v2-file', $salt);

    $counter = 0;
    while (true) {
        $head = akvabackup_read_n($fin, 4 + 1 + AKVABACKUP_TAG_LEN);
        if (strlen($head) !== 4 + 1 + AKVABACKUP_TAG_LEN) {
            akvabackup_fail('unexpected EOF before FINAL chunk');
        }
        $len = (int) unpack('N', substr($head, 0, 4))[1];
        $final = ord($head[4]) === 1;
        $tag = substr($head, 5, AKVABACKUP_TAG_LEN);
        if ($len > AKVABACKUP_CHUNK) {
            akvabackup_fail('corrupt chunk length');
        }
        $cipher = akvabackup_read_n($fin, $len);
        if (strlen($cipher) !== $len) {
            akvabackup_fail('truncated chunk');
        }
        $clear = openssl_decrypt(
            $cipher,
            'aes-256-gcm',
            $subkey,
            OPENSSL_RAW_DATA,
            akvabackup_nonce($counter),
            $tag,
            akvabackup_aad($counter, $final)
        );
        if ($clear === false) {
            akvabackup_fail('decryption failed: wrong key or tampered file');
        }
        akvabackup_write_all($fout, $clear);
        ++$counter;
        if ($final) {
            if (akvabackup_read_n($fin, 1) !== '') {
                akvabackup_fail('trailing data after FINAL chunk');
            }
            break;
        }
    }
} finally {
    fclose($fin);
    fclose($fout);
}

fwrite(STDERR, 'OK: decrypted ' . $inPath . ' -> ' . $outPath . "\n");
exit(0);

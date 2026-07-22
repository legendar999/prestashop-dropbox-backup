<?php

declare(strict_types=1);

namespace Akvabackup\Dropbox;

/**
 * Computes Dropbox's "content_hash" locally, to verify uploaded files.
 * Algorithm: split the file into 4 MiB blocks, sha256 each block (binary output),
 * concatenate the binary outputs and sha256 the concatenation, result in hex.
 * Empty file -> sha256 of the empty string.
 */
final class ContentHasher
{
    private const BLOCK = 4194304; // 4 MiB

    public static function hashFile(string $path): string
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new DropboxException('Unable to open file for hashing: ' . $path);
        }

        $concat = '';
        try {
            while (!feof($fh)) {
                // Collect exactly one block (fread may return less than requested).
                $block = '';
                $remaining = self::BLOCK;
                while ($remaining > 0 && !feof($fh)) {
                    $chunk = fread($fh, $remaining);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $block .= $chunk;
                    $remaining -= strlen($chunk);
                }
                if ($block === '') {
                    break;
                }
                $concat .= hash('sha256', $block, true);
            }
        } finally {
            fclose($fh);
        }

        return hash('sha256', $concat);
    }
}

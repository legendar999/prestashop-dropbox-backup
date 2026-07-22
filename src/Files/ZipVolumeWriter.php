<?php

declare(strict_types=1);

namespace Akvabackup\Files;

/**
 * Builds ONE complete zip volume synchronously (measured ~10-25 s for 450 MB, fits any
 * tick budget). Already-compressed / lossy-binary extensions are STOREd; everything else
 * DEFLATEd. A volume closes at the first file that would push the uncompressed total past
 * $maxBytes, except that at least one file is always accepted (an oversized single file
 * gets its own volume; ZipArchive writes zip64 automatically).
 */
final class ZipVolumeWriter
{
    /** @var string[] extensions stored without recompression */
    private const STORE_EXT = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif',
        'zip', 'gz', 'bz2', '7z', 'rar',
        'woff', 'woff2', 'mp4', 'webm', 'pdf',
    ];

    /**
     * @return array{last_rel:?string, files_n:int, bytes:int, exhausted:bool}
     */
    public function buildVolume(TreeScanner $scanner, ?string $afterRel, string $zipPath, int $maxBytes = 471859200): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('ZipVolumeWriter: cannot open ' . $zipPath);
        }

        $lastRel = null;
        $filesN = 0;
        $bytes = 0;

        foreach ($scanner->iterate($afterRel) as $f) {
            // Stop before a file that would overflow the volume; it becomes the first file
            // of the next volume (next call resumes strictly after $lastRel).
            if ($filesN > 0 && ($bytes + $f['size']) > $maxBytes) {
                $this->close($zip, $zipPath, true);

                return ['last_rel' => $lastRel, 'files_n' => $filesN, 'bytes' => $bytes, 'exhausted' => false];
            }

            if (!$zip->addFile($f['abs'], $f['rel'])) {
                throw new \RuntimeException('ZipVolumeWriter: addFile failed for ' . $f['rel']);
            }
            $ext = strtolower(pathinfo($f['rel'], PATHINFO_EXTENSION));
            $method = in_array($ext, self::STORE_EXT, true) ? \ZipArchive::CM_STORE : \ZipArchive::CM_DEFLATE;
            $zip->setCompressionName($f['rel'], $method);

            $lastRel = $f['rel'];
            ++$filesN;
            $bytes += $f['size'];
        }

        $this->close($zip, $zipPath, $filesN > 0);

        return ['last_rel' => $lastRel, 'files_n' => $filesN, 'bytes' => $bytes, 'exhausted' => true];
    }

    /** close() performs the actual compression; a false return on a non-empty volume is a hard error. */
    private function close(\ZipArchive $zip, string $zipPath, bool $mustSucceed): void
    {
        $ok = $zip->close();
        if ($mustSucceed && $ok !== true) {
            throw new \RuntimeException('ZipVolumeWriter: close failed for ' . $zipPath);
        }
    }
}

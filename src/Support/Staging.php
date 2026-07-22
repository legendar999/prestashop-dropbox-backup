<?php

declare(strict_types=1);

namespace Akvabackup\Support;

/**
 * Per-run staging area under _PS_ROOT_DIR_/var/akvabackup/<id_run>/ where the DB dump and
 * zip volumes are built + encrypted before upload. The parent is denied web access and is
 * itself excluded from the backup (AKVABACKUP_EXCLUDES ships var/akvabackup/*).
 */
final class Staging
{
    private static function base(): string
    {
        return rtrim(str_replace('\\', '/', _PS_ROOT_DIR_), '/') . '/var/akvabackup';
    }

    /** Absolute path of the run's staging dir; creates it + the parent guards on first use. */
    public static function dir(int $idRun): string
    {
        $base = self::base();
        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
        }
        self::guardParent($base);

        $dir = $base . '/' . $idRun;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir;
    }

    public static function purgeRun(int $idRun): void
    {
        self::rrmdir(self::base() . '/' . $idRun);
    }

    /** Uninstall + retention safety net: remove the whole staging tree. */
    public static function purgeAll(): void
    {
        self::rrmdir(self::base());
    }

    private static function guardParent(string $base): void
    {
        $ht = $base . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Require all denied\n");
        }
        $idx = $base . '/index.php';
        if (!is_file($idx)) {
            // Non-redirecting guard: never emit a Location header here (PS9.1 redirect-loop lesson).
            @file_put_contents($idx, "<?php\nheader('HTTP/1.0 403 Forbidden');\nexit;\n");
        }
    }

    private static function rrmdir(string $path): void
    {
        if ($path === '' || !file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        $entries = @scandir($path);
        if ($entries !== false) {
            foreach ($entries as $e) {
                if ($e !== '.' && $e !== '..') {
                    self::rrmdir($path . '/' . $e);
                }
            }
        }
        @rmdir($path);
    }
}

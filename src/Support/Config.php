<?php

declare(strict_types=1);

namespace Akvabackup\Support;

/**
 * Thin typed accessors over Configuration::get/updateGlobalValue for the pinned
 * AKVABACKUP_* keys. Only the plain (non-secret) keys the job engine needs live here;
 * the encrypted keys (app secret, refresh/access token, enc key) are owned by
 * TokenStore / KeyManager. All keys are GLOBAL (multistore-shared) by contract.
 */
final class Config
{
    public const ENABLED = 'AKVABACKUP_ENABLED';
    public const APP_KEY = 'AKVABACKUP_APP_KEY';
    public const CRON_TOKEN = 'AKVABACKUP_CRON_TOKEN';
    public const HOUR = 'AKVABACKUP_HOUR';
    public const RET_DAILY = 'AKVABACKUP_RET_DAILY';
    public const RET_WEEKLY = 'AKVABACKUP_RET_WEEKLY';
    public const RET_MONTHLY = 'AKVABACKUP_RET_MONTHLY';
    public const EXCLUDES = 'AKVABACKUP_EXCLUDES';
    public const DB_EXCLUDES = 'AKVABACKUP_DB_EXCLUDES';
    public const ALERT_EMAIL = 'AKVABACKUP_ALERT_EMAIL';
    public const TICK_BUDGET = 'AKVABACKUP_TICK_BUDGET';
    public const LAST_OK = 'AKVABACKUP_LAST_OK';
    public const INC_DB = 'AKVABACKUP_INC_DB';
    public const INC_IMG = 'AKVABACKUP_INC_IMG';
    public const INC_MODULES = 'AKVABACKUP_INC_MODULES';
    public const INC_THEMES = 'AKVABACKUP_INC_THEMES';
    public const INC_DOWNLOAD = 'AKVABACKUP_INC_DOWNLOAD';
    public const INC_MAILS = 'AKVABACKUP_INC_MAILS';

    /**
     * Component toggles (v1.1.0): each file toggle maps to the exclude globs appended to the
     * scanner when it is OFF. Missing key = '1' (ON) so pre-upgrade installs keep full backups.
     *
     * @var array<string,string[]>
     */
    public const COMPONENT_GLOBS = [
        self::INC_IMG => ['img/*'],
        self::INC_MODULES => ['modules/*'],
        self::INC_THEMES => ['themes/*'],
        self::INC_DOWNLOAD => ['download/*', 'upload/*'],
        self::INC_MAILS => ['mails/*'],
    ];

    public static function raw(string $key, string $default = ''): string
    {
        $v = \Configuration::getGlobalValue($key);

        return ($v === false || $v === null) ? $default : (string) $v;
    }

    public static function enabled(): bool
    {
        return (bool) (int) self::raw(self::ENABLED, '0');
    }

    public static function appKey(): string
    {
        return self::raw(self::APP_KEY);
    }

    public static function cronToken(): string
    {
        return self::raw(self::CRON_TOKEN);
    }

    public static function hour(): int
    {
        return self::clampInt((int) self::raw(self::HOUR, '2'), 0, 23);
    }

    public static function retDaily(): int
    {
        return max(0, (int) self::raw(self::RET_DAILY, '14'));
    }

    public static function retWeekly(): int
    {
        return max(0, (int) self::raw(self::RET_WEEKLY, '8'));
    }

    public static function retMonthly(): int
    {
        return max(0, (int) self::raw(self::RET_MONTHLY, '6'));
    }

    /** @return string[] newline-separated glob excludes, trimmed, blanks dropped */
    public static function excludes(): array
    {
        return self::lines(self::raw(self::EXCLUDES));
    }

    /** True when the DB dump phase should run (missing key = ON, pre-upgrade behaviour). */
    public static function includeDb(): bool
    {
        return (bool) (int) self::raw(self::INC_DB, '1');
    }

    public static function includeComponent(string $key): bool
    {
        return (bool) (int) self::raw($key, '1');
    }

    /**
     * Manual excludes + the globs of every file component whose toggle is OFF.
     * This is what the archive phase feeds the TreeScanner.
     *
     * @return string[]
     */
    public static function effectiveExcludes(): array
    {
        $out = self::excludes();
        foreach (self::COMPONENT_GLOBS as $key => $globs) {
            if (!self::includeComponent($key)) {
                foreach ($globs as $g) {
                    $out[] = $g;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** @return string[] table names WITHOUT the DB prefix */
    public static function dbExcludes(): array
    {
        return self::lines(self::raw(self::DB_EXCLUDES));
    }

    public static function alertEmail(): string
    {
        return trim(self::raw(self::ALERT_EMAIL));
    }

    /** Raw configured budget (default 60). tick() applies the hard cap of 90. */
    public static function tickBudget(): int
    {
        return max(5, (int) self::raw(self::TICK_BUDGET, '60'));
    }

    public static function lastOk(): string
    {
        return self::raw(self::LAST_OK);
    }

    public static function setLastOk(string $value): void
    {
        \Configuration::updateGlobalValue(self::LAST_OK, $value);
    }

    /** @return string[] */
    private static function lines(string $value): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $value) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    private static function clampInt(int $v, int $min, int $max): int
    {
        return max($min, min($max, $v));
    }
}

<?php

declare(strict_types=1);

namespace Akvabackup\Job;

use Akvabackup\Dropbox\TokenStore;
use Akvabackup\Support\Config;

/**
 * Decides whether a fresh DAILY run may be created this tick. Never touches Dropbox beyond the cheap
 * local isConnected() config check (no network) so a scheduler evaluation costs nothing.
 */
final class Scheduler
{
    /**
     * True only when: master switch on, Dropbox connected, the local clock has passed the configured
     * start hour, no run of any type has been created today, and no run is currently active.
     */
    public static function shouldStart(): bool
    {
        if (!Config::enabled()) {
            return false;
        }
        if (!(new TokenStore())->isConnected()) {
            return false;
        }
        if ((int) date('H') < Config::hour()) {
            return false;
        }

        $table = _DB_PREFIX_ . 'akvabackup_run';
        $db = \Db::getInstance();

        // "date_add is today" uses the DB clock (CURDATE) to stay TZ-consistent with the stored NOW() rows.
        $startedToday = (int) $db->getValue('SELECT COUNT(*) FROM `' . $table . '` WHERE DATE(date_add) = CURDATE()');
        if ($startedToday > 0) {
            return false;
        }

        $active = (int) $db->getValue('SELECT COUNT(*) FROM `' . $table . "` WHERE state NOT IN ('done','error')");

        return $active === 0;
    }
}

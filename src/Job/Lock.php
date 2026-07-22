<?php

declare(strict_types=1);

namespace Akvabackup\Job;

/**
 * MySQL advisory single-flight lock (GET_LOCK, 0s timeout). Fail-open: a lock error must NEVER stop
 * cron. Names are namespaced with the DB prefix because GET_LOCK names are server-global across
 * shared-host tenants, and capped at 64 chars. Lock name prefix: 'akvabackup_'.
 */
final class Lock
{
    private ?string $name = null;

    public function acquire(string $key): bool
    {
        $name = substr('akvabackup_' . _DB_PREFIX_ . $key, 0, 64);
        try {
            $rows = \Db::getInstance()->executeS('SELECT GET_LOCK(\'' . pSQL($name) . '\', 0) AS l', true, false);
            $got = is_array($rows) && $rows !== [] && (int) $rows[0]['l'] === 1;
            if ($got) {
                $this->name = $name;
            }

            return $got;
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('[akvabackup] lock acquire failed (proceeding unlocked): ' . $e->getMessage(), 1);

            return true; // fail-open
        }
    }

    public function release(): void
    {
        if ($this->name === null) {
            return;
        }
        try {
            \Db::getInstance()->executeS('SELECT RELEASE_LOCK(\'' . pSQL($this->name) . '\')', true, false);
        } catch (\Throwable) {
            // connection close releases the lock automatically
        }
        $this->name = null;
    }
}

<?php

declare(strict_types=1);

namespace Akvabackup\Job;

use Akvabackup\Dropbox\Client;
use Akvabackup\Support\Config;
use Akvabackup\Support\Staging;

/**
 * GFS prune of the Dropbox app-folder root. Remote run folders are named "YYYY-MM-DD_<id_run>".
 * Keep set = newest RET_DAILY folders by date + the first-of-week folder for the newest RET_WEEKLY
 * ISO weeks + the first-of-month folder for the newest RET_MONTHLY months. Everything else is deleted.
 * The folder of the run being finalized is never deleted (belt-and-braces on top of the date logic).
 */
final class Retention
{
    /** @return array{deleted:string[]} */
    public static function prune(Client $client, int $currentRun): array
    {
        $folders = self::listRunFolders($client);

        // Sort by date desc, then id desc (newest first) for the daily window and first-of-period picks.
        usort($folders, static function (array $a, array $b): int {
            return $b['date'] <=> $a['date'] ?: $b['id'] <=> $a['id'];
        });

        $keep = [];

        // Daily: newest N folders outright.
        foreach (array_slice($folders, 0, Config::retDaily()) as $f) {
            $keep[$f['name']] = true;
        }

        // Weekly: first (earliest-dated) folder of each of the newest RET_WEEKLY ISO weeks.
        self::keepFirstOfPeriod($folders, static function (array $f): string {
            $ts = strtotime($f['date']) ?: 0;

            return date('o-W', $ts);
        }, Config::retWeekly(), $keep);

        // Monthly: first (earliest-dated) folder of each of the newest RET_MONTHLY months.
        self::keepFirstOfPeriod($folders, static function (array $f): string {
            return substr($f['date'], 0, 7); // YYYY-MM
        }, Config::retMonthly(), $keep);

        $deleted = [];
        foreach ($folders as $f) {
            if ($f['id'] === $currentRun || isset($keep[$f['name']])) {
                continue;
            }
            try {
                $client->delete('/' . $f['name']);
                $deleted[] = $f['name'];
            } catch (\Throwable $e) {
                \PrestaShopLogger::addLog('[akvabackup] retention delete failed for ' . $f['name'] . ': ' . $e->getMessage(), 2);
            }
        }

        Staging::purgeRun($currentRun);

        return ['deleted' => $deleted];
    }

    /**
     * @param array<int,array{name:string,date:string,id:int}> $folders newest-first
     * @param callable(array{name:string,date:string,id:int}):string $periodKey
     * @param array<string,bool> $keep
     */
    private static function keepFirstOfPeriod(array $folders, callable $periodKey, int $count, array &$keep): void
    {
        if ($count <= 0) {
            return;
        }

        // Earliest-dated folder per period = the "first run" of that period.
        $firstOfPeriod = [];
        foreach ($folders as $f) {
            $k = $periodKey($f);
            if (!isset($firstOfPeriod[$k]) || $f['date'] < $firstOfPeriod[$k]['date']
                || ($f['date'] === $firstOfPeriod[$k]['date'] && $f['id'] < $firstOfPeriod[$k]['id'])) {
                $firstOfPeriod[$k] = $f;
            }
        }

        // Newest periods first.
        krsort($firstOfPeriod);
        foreach (array_slice($firstOfPeriod, 0, $count) as $f) {
            $keep[$f['name']] = true;
        }
    }

    /** @return array<int,array{name:string,date:string,id:int}> */
    private static function listRunFolders(Client $client): array
    {
        $out = [];
        $entries = $client->listFolder('');
        foreach ($entries as $entry) {
            if (($entry['.tag'] ?? '') !== 'folder') {
                continue;
            }
            $name = (string) ($entry['name'] ?? '');
            if (!preg_match('/^(\d{4}-\d{2}-\d{2})_(\d+)$/', $name, $m)) {
                continue;
            }
            $out[] = ['name' => $name, 'date' => $m[1], 'id' => (int) $m[2]];
        }

        return $out;
    }
}

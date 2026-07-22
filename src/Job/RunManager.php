<?php

declare(strict_types=1);

namespace Akvabackup\Job;

use Akvabackup\Crypto\KeyManager;
use Akvabackup\Crypto\SecretStream;
use Akvabackup\Dropbox\Client;
use Akvabackup\Dropbox\ContentHasher;
use Akvabackup\Dump\ChunkedDumper;
use Akvabackup\Files\TreeScanner;
use Akvabackup\Files\ZipVolumeWriter;
use Akvabackup\Support\Config;
use Akvabackup\Support\Staging;

/**
 * The resumable backup state machine, driven one bounded "tick" at a time by the cron controller (and
 * by the BO "run now" button, which only creates the run — the cron carries it forward). Every phase
 * persists its cursor + date_upd after each unit of work (dump batch, volume, upload chunk) so a CF 524
 * kill mid-tick loses at most the current in-flight unit. All timestamps are written with MySQL NOW().
 */
final class RunManager
{
    private const HARD_BUDGET_CAP = 90;      // seconds; CF 524 guard
    private const REAP_HOURS = 6;            // an active run untouched this long is presumed dead
    private const SMALL_UPLOAD = 8388608;    // 8 MiB: below this use single-shot upload, above use a session
    private const UPLOAD_CHUNK = 25165824;   // 24 MiB append size
    private const ARCHIVE_MIN_BUDGET = 30.0; // never START a volume with less than this left (buildVolume is ~10-25 s)

    public function __construct(private Client $client)
    {
    }

    /** @return array<string,mixed> */
    public function tick(): array
    {
        $budget = min(Config::tickBudget(), self::HARD_BUDGET_CAP);
        $deadline = microtime(true) + $budget;

        $reaped = $this->reapStuck();
        if ($reaped !== null) {
            return ['reaped' => $reaped, 'state' => 'error'];
        }

        $run = $this->activeRun();
        if ($run === null) {
            if (!Scheduler::shouldStart()) {
                return ['idle' => true, 'last_ok' => Config::lastOk()];
            }
            $idRun = $this->createRun('daily');
            $run = $this->loadRun($idRun);
        }

        return $this->advance((int) $run['id_run'], $deadline);
    }

    /** Creates a manual run (refuses if any run is active) and returns its id. */
    public function startManual(): int
    {
        if ($this->activeRun() !== null) {
            throw new \RuntimeException('a backup run is already active');
        }

        return $this->createRun('manual');
    }

    public static function hasActiveManualRun(): bool
    {
        $table = _DB_PREFIX_ . 'akvabackup_run';
        $id = \Db::getInstance()->getValue('SELECT id_run FROM `' . $table . "` WHERE type = 'manual' AND state NOT IN ('done','error') LIMIT 1");

        return (bool) $id;
    }

    // ---- state machine -----------------------------------------------------

    /** @return array<string,mixed> */
    private function advance(int $idRun, float $deadline): array
    {
        try {
            while (microtime(true) < $deadline) {
                $run = $this->loadRun($idRun);
                if ($run === null) {
                    break;
                }
                $state = (string) $run['state'];
                if ($state === 'done' || $state === 'error') {
                    break;
                }

                $continue = match ($state) {
                    'pending' => $this->toDumping($idRun),
                    'dumping' => $this->phaseDump($run, $deadline),
                    'archiving' => $this->phaseArchive($run, $deadline),
                    'uploading' => $this->phaseUpload($run, $deadline),
                    'verifying' => $this->phaseVerify($run, $deadline),
                    'rotating' => $this->phaseRotate($run),
                    default => false,
                };

                if (!$continue) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            $this->fail($idRun, $e->getMessage());
        }

        return $this->status($this->loadRun($idRun) ?? ['id_run' => $idRun, 'state' => 'error']);
    }

    private function toDumping(int $idRun): bool
    {
        $this->setState($idRun, 'dumping');
        $this->setCursor($idRun, null);
        $this->saveStats($idRun, []);

        return true;
    }

    /** @param array<string,mixed> $run */
    private function phaseDump(array $run, float $deadline): bool
    {
        $idRun = (int) $run['id_run'];

        // Component toggle (v1.1.0): DB dump disabled — skip the phase entirely, no db item.
        if (!Config::includeDb()) {
            $this->setCursor($idRun, null);
            $this->setState($idRun, 'archiving');

            return true;
        }

        $stagingDir = Staging::dir($idRun);
        $gzPath = $stagingDir . '/db.sql.gz';
        $encPath = $gzPath . '.enc';

        // Idempotent re-entry: the db item row is the LAST step of this phase — if it exists,
        // a previous tick finished the dump and was killed before the state flip. Never re-dump
        // (re-entering ChunkedDumper with a done cursor would encrypt an empty gz OVER the good
        // one — a silently empty DB backup).
        if ((bool) $this->db()->getValue('SELECT id_item FROM `' . $this->tItem() . '` WHERE id_run = ' . $idRun . " AND kind = 'db'")) {
            $this->setCursor($idRun, null);
            $this->setState($idRun, 'archiving');

            return true;
        }

        $cursor = $this->decodeJson((string) ($run['cursor'] ?? ''));

        // Same guard, one window earlier: encrypted dump present and plain gz already unlinked
        // means the dump itself completed — skip straight to recording it.
        if (!(is_file($encPath) && !is_file($gzPath))) {
            // Fresh phase entry (no cursor ever persisted): any existing gz content is an orphan
            // from a killed first slice — possibly a TRUNCATED gzip member that would corrupt the
            // whole concatenated stream. Start clean.
            if (!isset($cursor['gz_bytes']) && is_file($gzPath)) {
                @unlink($gzPath);
            }

            // Self-heal after a hard kill: gz bytes past the last persisted cursor belong to a batch
            // whose cursor never landed — truncate them or the resume would re-append the same rows
            // (duplicate INSERTs that only surface at RESTORE time as primary-key errors).
            if (isset($cursor['gz_bytes']) && is_file($gzPath)) {
                clearstatcache(true, $gzPath);
                $known = (int) $cursor['gz_bytes'];
                if ((int) filesize($gzPath) > $known) {
                    $fh = fopen($gzPath, 'r+b');
                    if ($fh !== false) {
                        ftruncate($fh, $known);
                        fclose($fh);
                    }
                }
            }

            $dumper = new ChunkedDumper($gzPath, Config::dbExcludes());
            $done = false;
            // Short sub-slices so the persisted cursor never trails the gz file by more than ~10 s of rows.
            while (microtime(true) < $deadline) {
                $slice = min($deadline, microtime(true) + 10.0);
                $res = $dumper->advance($cursor, $slice);
                $cursor = $res['cursor'];
                clearstatcache(true, $gzPath);
                $cursor['gz_bytes'] = (int) @filesize($gzPath);
                $this->setCursor($idRun, $cursor);
                if (!empty($res['done'])) {
                    $done = true;
                    break;
                }
            }

            if (!$done) {
                return false; // deadline reached mid-dump; resume next tick
            }

            SecretStream::encryptFile($gzPath, $encPath, KeyManager::getKey());
            @unlink($gzPath);
        }

        $size = (int) @filesize($encPath);
        $hash = ContentHasher::hashFile($encPath);
        $remote = '/' . $this->remoteFolder($run) . '/db.sql.gz.enc';
        $this->insertItem($idRun, 'db', 0, $encPath, $remote, $size, $hash);

        $this->bumpStats($idRun, ['db_bytes' => $size]);
        $this->setCursor($idRun, null);
        $this->setState($idRun, 'archiving');

        return true;
    }

    /** @param array<string,mixed> $run */
    private function phaseArchive(array $run, float $deadline): bool
    {
        if ($deadline - microtime(true) < self::ARCHIVE_MIN_BUDGET) {
            return false; // not enough budget to safely build a whole volume
        }

        $idRun = (int) $run['id_run'];
        $stagingDir = Staging::dir($idRun);
        $cursor = $this->decodeJson((string) ($run['cursor'] ?? ''));
        $afterRel = isset($cursor['after_rel']) ? (string) $cursor['after_rel'] : null;

        $seq = (int) $this->db()->getValue('SELECT COUNT(*) FROM `' . $this->tItem() . '` WHERE id_run = ' . $idRun . " AND kind = 'files'");
        $zipPath = $stagingDir . '/files_' . $seq . '.zip';

        $scanner = new TreeScanner(_PS_ROOT_DIR_, Config::effectiveExcludes());
        $res = (new ZipVolumeWriter())->buildVolume($scanner, $afterRel, $zipPath);

        $cursor['after_rel'] = $res['last_rel'] ?? null;

        if ((int) ($res['files_n'] ?? 0) > 0) {
            $encPath = $zipPath . '.enc';
            SecretStream::encryptFile($zipPath, $encPath, KeyManager::getKey());
            @unlink($zipPath);

            $size = (int) @filesize($encPath);
            $hash = ContentHasher::hashFile($encPath);
            $remote = '/' . $this->remoteFolder($run) . '/files_' . $seq . '.zip.enc';
            $this->insertItem($idRun, 'files', $seq, $encPath, $remote, $size, $hash);
            // Cursor lands in the same breath as the item row (item FIRST: a kill between the two
            // costs a harmless duplicate volume; the reverse order would silently DROP a file range).
            $this->setCursor($idRun, $cursor);

            $this->bumpStats($idRun, [
                'files_bytes' => $size,
                'files_n' => (int) $res['files_n'],
                'volumes_n' => 1,
            ]);
        } else {
            $this->setCursor($idRun, $cursor);
        }

        if (!empty($res['exhausted'])) {
            $this->writeMeta($run, $stagingDir);
            $this->setCursor($idRun, null);
            $this->setState($idRun, 'uploading');
        }

        return true;
    }

    /** @param array<string,mixed> $run */
    private function phaseUpload(array $run, float $deadline): bool
    {
        $idRun = (int) $run['id_run'];
        $item = $this->db()->getRow(
            'SELECT * FROM `' . $this->tItem() . '` WHERE id_run = ' . $idRun
            . " AND state IN ('pending','built','uploading')"
            . " ORDER BY FIELD(kind,'db','files','meta'), seq ASC"
        );

        if (!$item) {
            $this->setState($idRun, 'verifying');

            return true;
        }

        return $this->uploadItem($item, $deadline);
    }

    /**
     * @param array<string,mixed> $item
     * @return bool true if the item finished (look for more work), false if the tick budget ran out
     */
    private function uploadItem(array $item, float $deadline): bool
    {
        $idItem = (int) $item['id_item'];
        $idRun = (int) $item['id_run'];
        $local = (string) $item['local_path'];
        $remote = (string) $item['remote_path'];
        $size = (int) @filesize($local);

        if ($size <= self::SMALL_UPLOAD) {
            $this->client->uploadSmall($local, $remote);
            $this->setItemState($idItem, 'uploaded', ['upload_offset' => $size]);
            $this->bumpStats($idRun, ['uploaded_bytes' => $size]);

            return true;
        }

        $sessionId = (string) ($item['session_id'] ?? '');
        $offset = (int) $item['upload_offset'];
        if ($sessionId === '') {
            $sessionId = $this->client->sessionStart();
            $offset = 0;
            $this->setItemState($idItem, 'uploading', ['session_id' => $sessionId, 'upload_offset' => 0]);
        }

        $fh = fopen($local, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('cannot open volume for upload: ' . basename($local));
        }

        try {
            while ($offset < $size && microtime(true) < $deadline) {
                if (fseek($fh, $offset) !== 0) {
                    throw new \RuntimeException('fseek failed on ' . basename($local));
                }
                $len = min(self::UPLOAD_CHUNK, $size - $offset);
                $bytes = fread($fh, $len);
                if ($bytes === false) {
                    throw new \RuntimeException('fread failed on ' . basename($local));
                }
                $offset = $this->client->sessionAppend($sessionId, $bytes, $offset);
                $this->setItemField($idItem, 'upload_offset', (string) $offset);
                $this->touch($idRun);
            }
        } finally {
            fclose($fh);
        }

        if ($offset < $size) {
            return false; // budget exhausted mid-file; resume from persisted offset next tick
        }

        $this->client->sessionFinish($sessionId, $size, $remote);
        $this->setItemState($idItem, 'uploaded', ['upload_offset' => $size]);
        $this->bumpStats($idRun, ['uploaded_bytes' => $size]);

        return true;
    }

    /** @param array<string,mixed> $run */
    private function phaseVerify(array $run, float $deadline): bool
    {
        $idRun = (int) $run['id_run'];
        $items = $this->db()->executeS(
            'SELECT * FROM `' . $this->tItem() . '` WHERE id_run = ' . $idRun . " AND state = 'uploaded' ORDER BY id_item ASC"
        );
        $items = is_array($items) ? $items : [];

        foreach ($items as $item) {
            if (microtime(true) >= $deadline) {
                return false;
            }
            $idItem = (int) $item['id_item'];
            $remote = (string) $item['remote_path'];
            $local = (string) $item['content_hash'];

            $meta = $this->client->rpc('/2/files/get_metadata', ['path' => $remote]);
            $server = (string) ($meta['content_hash'] ?? '');

            if ($server !== '' && $local !== '' && hash_equals($local, $server)) {
                $this->setItemState($idItem, 'verified');
                $this->touch($idRun);
                continue;
            }

            // One retry: re-queue this single item for a fresh upload. Second failure fails the run.
            $stats = $this->loadStats($idRun);
            $retries = isset($stats['verify_retries']) && is_array($stats['verify_retries']) ? $stats['verify_retries'] : [];
            if (isset($retries[$idItem])) {
                throw new \RuntimeException('content hash mismatch after retry on item ' . $idItem . ' (' . basename($remote) . ')');
            }
            $retries[$idItem] = 1;
            $stats['verify_retries'] = $retries;
            $this->saveStats($idRun, $stats);

            $this->setItemState($idItem, 'pending', ['upload_offset' => 0, 'session_id' => null]);
            $this->setState($idRun, 'uploading');

            return true;
        }

        $this->setState($idRun, 'rotating');

        return true;
    }

    /** @param array<string,mixed> $run */
    private function phaseRotate(array $run): bool
    {
        $idRun = (int) $run['id_run'];

        try {
            Retention::prune($this->client, $idRun);
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('[akvabackup] retention prune failed on run #' . $idRun . ': ' . $e->getMessage(), 2);
        }

        Staging::purgeRun($idRun);

        $now = (string) $this->db()->getValue('SELECT NOW()');
        Config::setLastOk($now);

        $stats = $this->loadStats($idRun);
        $stats['duration_s'] = (int) $this->db()->getValue('SELECT TIMESTAMPDIFF(SECOND, date_add, NOW()) FROM `' . $this->tRun() . '` WHERE id_run = ' . $idRun);
        $this->saveStats($idRun, $stats);

        $this->db()->execute('UPDATE `' . $this->tRun() . "` SET state = 'done', finished_at = NOW(), date_upd = NOW() WHERE id_run = " . $idRun);

        return false;
    }

    // ---- meta manifest -----------------------------------------------------

    /** @param array<string,mixed> $run */
    private function writeMeta(array $run, string $stagingDir): void
    {
        $idRun = (int) $run['id_run'];
        $items = $this->db()->executeS(
            'SELECT kind, seq, remote_path, size_bytes, content_hash FROM `' . $this->tItem() . '` WHERE id_run = ' . $idRun
            . " ORDER BY FIELD(kind,'db','files'), seq ASC"
        );
        $items = is_array($items) ? $items : [];

        $manifest = [
            'module' => 'akvabackup',
            'module_version' => $this->moduleVersion(),
            'ps_version' => _PS_VERSION_,
            'db_name' => _DB_NAME_,
            'run_id' => $idRun,
            'type' => (string) $run['type'],
            'date' => (string) $run['date_add'],
            'stats' => $this->loadStats($idRun),
            'volumes' => array_map(static function (array $it): array {
                return [
                    'kind' => $it['kind'],
                    'seq' => (int) $it['seq'],
                    'remote_path' => $it['remote_path'],
                    'size_bytes' => (int) $it['size_bytes'],
                    'content_hash' => $it['content_hash'],
                ];
            }, $items),
        ];

        $metaPath = $stagingDir . '/meta.json';
        file_put_contents($metaPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // The manifest leaks DB name, PS version and run stats — encrypt it like everything else;
        // Dropbox must only ever hold opaque blobs (tools/decrypt.php reads it back).
        $encPath = $metaPath . '.enc';
        SecretStream::encryptFile($metaPath, $encPath, KeyManager::getKey());
        @unlink($metaPath);

        $size = (int) @filesize($encPath);
        $hash = ContentHasher::hashFile($encPath);
        $remote = '/' . $this->remoteFolder($run) . '/meta.json.enc';
        $this->insertItem($idRun, 'meta', 0, $encPath, $remote, $size, $hash);
    }

    // ---- reaper + failure --------------------------------------------------

    /** @return int|null id of a run reaped as stuck, or null */
    private function reapStuck(): ?int
    {
        $id = (int) $this->db()->getValue(
            'SELECT id_run FROM `' . $this->tRun() . "` WHERE state NOT IN ('done','error')"
            . ' AND date_upd < (NOW() - INTERVAL ' . self::REAP_HOURS . ' HOUR) ORDER BY id_run ASC LIMIT 1'
        );
        if ($id <= 0) {
            return null;
        }

        $this->fail($id, 'run stalled: no progress for more than ' . self::REAP_HOURS . ' hours (reaped)');

        return $id;
    }

    private function fail(int $idRun, string $message): void
    {
        $this->db()->execute(
            'UPDATE `' . $this->tRun() . "` SET state = 'error', error = '" . pSQL($message) . "', finished_at = NOW(), date_upd = NOW() WHERE id_run = " . $idRun
        );
        Alerts::failure($idRun, $message);
    }

    // ---- run/item persistence ---------------------------------------------

    private function createRun(string $type): int
    {
        $this->db()->execute(
            'INSERT INTO `' . $this->tRun() . '` (type, state, date_add, date_upd) VALUES '
            . "('" . pSQL($type) . "', 'pending', NOW(), NOW())"
        );

        return (int) $this->db()->Insert_ID();
    }

    /** @return array<string,mixed>|null */
    private function activeRun(): ?array
    {
        $row = $this->db()->getRow(
            'SELECT * FROM `' . $this->tRun() . "` WHERE state NOT IN ('done','error') ORDER BY id_run DESC"
        );

        return is_array($row) && $row !== [] ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function loadRun(int $idRun): ?array
    {
        $row = $this->db()->getRow('SELECT * FROM `' . $this->tRun() . '` WHERE id_run = ' . $idRun);

        return is_array($row) && $row !== [] ? $row : null;
    }

    private function insertItem(int $idRun, string $kind, int $seq, string $local, string $remote, int $size, string $hash): int
    {
        $this->db()->execute(
            'INSERT INTO `' . $this->tItem() . '` (id_run, kind, seq, local_path, remote_path, size_bytes, content_hash, state, date_add, date_upd) VALUES ('
            . $idRun . ", '" . pSQL($kind) . "', " . $seq . ", '" . pSQL($local) . "', '" . pSQL($remote) . "', " . $size . ", '" . pSQL($hash) . "', 'built', NOW(), NOW())"
        );

        return (int) $this->db()->Insert_ID();
    }

    /** @param array<string,mixed> $extra column => value (null clears the column) */
    private function setItemState(int $idItem, string $state, array $extra = []): void
    {
        $sets = ["state = '" . pSQL($state) . "'"];
        foreach ($extra as $col => $val) {
            $sets[] = $this->assign($col, $val);
        }
        $sets[] = 'date_upd = NOW()';
        $this->db()->execute('UPDATE `' . $this->tItem() . '` SET ' . implode(', ', $sets) . ' WHERE id_item = ' . $idItem);
    }

    private function setItemField(int $idItem, string $col, string $value): void
    {
        $this->db()->execute('UPDATE `' . $this->tItem() . '` SET ' . $this->assign($col, $value) . ', date_upd = NOW() WHERE id_item = ' . $idItem);
    }

    private function assign(string $col, mixed $val): string
    {
        $col = preg_replace('/[^a-z_]/', '', $col) ?? '';
        if ($val === null) {
            return '`' . $col . '` = NULL';
        }
        if (is_int($val)) {
            return '`' . $col . '` = ' . $val;
        }

        return '`' . $col . "` = '" . pSQL((string) $val) . "'";
    }

    private function setState(int $idRun, string $state): void
    {
        $this->db()->execute('UPDATE `' . $this->tRun() . "` SET state = '" . pSQL($state) . "', date_upd = NOW() WHERE id_run = " . $idRun);
    }

    /** @param array<string,mixed>|null $cursor */
    private function setCursor(int $idRun, ?array $cursor): void
    {
        if ($cursor === null) {
            $this->db()->execute('UPDATE `' . $this->tRun() . '` SET `cursor` = NULL, date_upd = NOW() WHERE id_run = ' . $idRun);

            return;
        }
        $json = json_encode($cursor, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $this->db()->execute('UPDATE `' . $this->tRun() . "` SET `cursor` = '" . pSQL($json) . "', date_upd = NOW() WHERE id_run = " . $idRun);
    }

    private function touch(int $idRun): void
    {
        $this->db()->execute('UPDATE `' . $this->tRun() . '` SET date_upd = NOW() WHERE id_run = ' . $idRun);
    }

    /** @return array<string,mixed> */
    private function loadStats(int $idRun): array
    {
        return $this->decodeJson((string) $this->db()->getValue('SELECT stats FROM `' . $this->tRun() . '` WHERE id_run = ' . $idRun));
    }

    /** @param array<string,mixed> $stats */
    private function saveStats(int $idRun, array $stats): void
    {
        $json = json_encode($stats, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $this->db()->execute('UPDATE `' . $this->tRun() . "` SET stats = '" . pSQL($json) . "', date_upd = NOW() WHERE id_run = " . $idRun);
    }

    /**
     * @param array<string,int|float|string> $delta numeric values accumulate, others overwrite
     * @return array<string,mixed>
     */
    private function bumpStats(int $idRun, array $delta): array
    {
        $stats = $this->loadStats($idRun);
        foreach ($delta as $k => $v) {
            if (is_int($v) || is_float($v)) {
                $stats[$k] = (is_numeric($stats[$k] ?? 0) ? ($stats[$k] ?? 0) : 0) + $v;
            } else {
                $stats[$k] = $v;
            }
        }
        $this->saveStats($idRun, $stats);

        return $stats;
    }

    // ---- helpers -----------------------------------------------------------

    /** @param array<string,mixed> $run */
    private function status(array $run): array
    {
        $error = isset($run['error']) ? (string) $run['error'] : '';

        return [
            'id_run' => (int) ($run['id_run'] ?? 0),
            'type' => (string) ($run['type'] ?? ''),
            'state' => (string) ($run['state'] ?? ''),
            'stats' => $this->decodeJson((string) ($run['stats'] ?? '')),
            'error' => $error === '' ? null : mb_substr($error, 0, 300),
        ];
    }

    /** @param array<string,mixed> $run */
    private function remoteFolder(array $run): string
    {
        $ts = strtotime((string) $run['date_add']) ?: time();

        return date('Y-m-d', $ts) . '_' . (int) $run['id_run'];
    }

    /** @return array<string,mixed> */
    private function decodeJson(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    private function moduleVersion(): string
    {
        try {
            $m = \Module::getInstanceByName('akvabackup');

            return $m ? (string) $m->version : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private function db(): \Db
    {
        return \Db::getInstance();
    }

    private function tRun(): string
    {
        return _DB_PREFIX_ . 'akvabackup_run';
    }

    private function tItem(): string
    {
        return _DB_PREFIX_ . 'akvabackup_item';
    }
}

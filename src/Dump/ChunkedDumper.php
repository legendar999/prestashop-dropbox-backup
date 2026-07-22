<?php
/**
 * akvabackup - Package C: chunked, cursor-resumable DB dumper.
 *
 * Output format is byte-compatible with PrestaShop core PrestaShopBackup::add()
 * (header comment, SET statements, per-table DROP + SHOW CREATE TABLE, INSERTs
 * batched 200 rows, values via pSQL($value, true), NULL as NULL). The only
 * difference is resumability: the dump advances one 1000-row LIMIT batch at a
 * time between deadline checks, appending to a multi-member gzip stream.
 *
 * @author Akva Modules
 */

declare(strict_types=1);

namespace Akvabackup\Dump;

final class ChunkedDumper
{
    private string $gzPath;

    /** @var array<string,true> full (prefixed) table names whose rows are skipped */
    private array $excludeSet = [];

    /**
     * @param string   $gzPath                path of the .sql.gz being appended to
     * @param string[] $excludeTablesNoPrefix table names WITHOUT _DB_PREFIX_; schema kept, rows skipped
     */
    public function __construct(string $gzPath, array $excludeTablesNoPrefix)
    {
        $this->gzPath = $gzPath;
        foreach ($excludeTablesNoPrefix as $t) {
            $t = trim((string) $t);
            if ($t !== '') {
                $this->excludeSet[$t] = true;
            }
        }
    }

    /**
     * Advance the dump until $deadline (microtime float).
     *
     * Cursor shape: ['tables'=>string[] (resolved once, prefix-filtered, sorted),
     * 'ti'=>int, 'offset'=>int, 'header_done'=>bool]. First call passes []. The
     * caller persists the returned cursor + date_upd after every batch.
     *
     * @return array{done:bool, cursor:array}
     */
    public function advance(array $cursor, float $deadline): array
    {
        if (!isset($cursor['tables']) || !is_array($cursor['tables'])) {
            $cursor = $this->initCursor();
        }

        $tables = $cursor['tables'];
        $ti = (int) ($cursor['ti'] ?? 0);
        $offset = (int) ($cursor['offset'] ?? 0);
        $headerDone = (bool) ($cursor['header_done'] ?? false);

        // 'ab6' = append, deflate level 6; each advance() writes one gzip member.
        $fp = gzopen($this->gzPath, 'ab6');
        if ($fp === false) {
            throw new \RuntimeException('akvabackup: cannot open dump file for append: ' . $this->gzPath);
        }

        try {
            if (!$headerDone) {
                gzwrite($fp, $this->header());
                $headerDone = true;
            }

            $db = \Db::getInstance();
            $prefix = \_DB_PREFIX_;
            $prefixLen = strlen($prefix);
            $count = count($tables);

            while ($ti < $count) {
                if (microtime(true) >= $deadline) {
                    break;
                }

                $table = (string) $tables[$ti];

                // Schema is written exactly once per table, at its first batch.
                if ($offset === 0) {
                    gzwrite($fp, $this->schemaBlock($db, $table));
                }

                // Excluded tables: schema only, no data rows.
                $noPrefix = substr($table, $prefixLen);
                if (isset($this->excludeSet[$noPrefix])) {
                    ++$ti;
                    $offset = 0;
                    continue;
                }

                // One resumable batch. OFFSET pagination is measured-fine on this DB.
                $rows = $db->executeS(
                    'SELECT * FROM `' . $table . '` LIMIT ' . $offset . ', 1000',
                    true,
                    false
                );
                $n = is_array($rows) ? count($rows) : 0;

                if ($n > 0) {
                    gzwrite($fp, $this->insertBatch($table, $rows));
                }

                if ($n < 1000) {
                    // Fewer than a full batch: table exhausted.
                    ++$ti;
                    $offset = 0;
                } else {
                    $offset += 1000;
                }
            }

            $done = $ti >= $count;
        } finally {
            gzclose($fp);
        }

        return [
            'done' => $done,
            'cursor' => [
                'tables' => $tables,
                'ti' => $ti,
                'offset' => $offset,
                'header_done' => $headerDone,
            ],
        ];
    }

    /** SHOW TABLES once, keep only _DB_PREFIX_ tables, sort for deterministic resume order. */
    private function initCursor(): array
    {
        $db = \Db::getInstance();
        $rows = $db->executeS('SHOW TABLES', true, false);
        $prefix = \_DB_PREFIX_;
        $prefixLen = strlen($prefix);

        $tables = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $t = current($row);
                if ($t === false || $t === null) {
                    continue;
                }
                $t = (string) $t;
                if (strlen($t) >= $prefixLen && strncmp($t, $prefix, $prefixLen) === 0) {
                    $tables[] = $t;
                }
            }
        }
        sort($tables, SORT_STRING);

        return [
            'tables' => $tables,
            'ti' => 0,
            'offset' => 0,
            'header_done' => false,
        ];
    }

    private function header(): string
    {
        $host = \Tools::getHttpHost(false, false) . \__PS_BASE_URI__;

        $out = '/* Backup for ' . $host . "\n *  at " . date('Y-m-d H:i:s') . "\n */\n";
        $out .= "\n" . "SET NAMES 'utf8mb4';";
        $out .= "\n" . 'SET FOREIGN_KEY_CHECKS = 0;';
        $out .= "\n" . "SET SESSION sql_mode = '';" . "\n\n";

        return $out;
    }

    private function schemaBlock(\Db $db, string $table): string
    {
        $schema = $db->executeS('SHOW CREATE TABLE `' . $table . '`', true, false);
        if (!is_array($schema) || count($schema) !== 1
            || !isset($schema[0]['Table'], $schema[0]['Create Table'])) {
            throw new \RuntimeException('akvabackup: unable to obtain the schema of ' . $table);
        }

        $name = (string) $schema[0]['Table'];
        $create = (string) $schema[0]['Create Table'];

        $out = '/* Scheme for table ' . $name . " */\n";
        $out .= 'DROP TABLE IF EXISTS `' . $name . '`;' . "\n";
        $out .= $create . ";\n\n";

        return $out;
    }

    /**
     * Emit a fetched batch as INSERT statements grouped 200 rows each, identical to
     * PrestaShopBackup::add(): '(v,...)' per row, ',\n' between rows in a group,
     * ';\n' at group/batch end, pSQL($value, true) escaping, NULL as NULL.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private function insertBatch(string $table, array $rows): string
    {
        $rows = array_values($rows);
        $n = count($rows);
        $out = '';

        for ($j = 0; $j < $n; ++$j) {
            if ($j % 200 === 0) {
                $out .= 'INSERT INTO `' . $table . "` VALUES\n";
            }

            $s = '(';
            foreach ($rows[$j] as $value) {
                if ($value === null) {
                    $s .= 'NULL,';
                } else {
                    $s .= "'" . \pSQL($value, true) . "',";
                }
            }
            $s = rtrim($s, ',');

            $lastInGroup = (($j % 200) === 199) || ($j === $n - 1);
            $out .= $s . ($lastInGroup ? ");\n" : "),\n");
        }

        return $out;
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Portable SQL dump using the same PDO connection as the app (no mysqldump binary).
 * Works on Windows and any host where PHP can connect to MySQL.
 */
class MysqlDumpViaPhp
{
    public function dumpToFile(string $targetPath): void
    {
        @set_time_limit(0);

        $driver = config('database.default');
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new \RuntimeException('PHP dump only supports mysql/mariadb.');
        }

        $dbName = (string) config("database.connections.{$driver}.database");
        if ($dbName === '') {
            throw new \RuntimeException('Database name is not configured.');
        }

        $handle = fopen($targetPath, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot write backup file: '.$targetPath);
        }

        try {
            $this->writeHeader($handle);

            $pdo = DB::connection()->getPdo();
            $tables = $this->listTables($dbName);

            $baseTables = [];
            $views = [];
            foreach ($tables as $t) {
                $name = $t['name'];
                if ($t['type'] === 'BASE TABLE') {
                    $baseTables[] = $name;
                } elseif ($t['type'] === 'VIEW') {
                    $views[] = $name;
                }
            }

            $baseTables = $this->orderBaseTablesForForeignKeys($dbName, $baseTables);

            fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

            foreach ($baseTables as $table) {
                $this->dumpBaseTable($handle, $pdo, $table);
            }

            foreach ($views as $view) {
                $this->dumpView($handle, $view);
            }

            fwrite($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($handle);
        }
    }

    /**
     * Parents before children so CREATE TABLE succeeds with InnoDB foreign keys.
     *
     * @param  list<string>  $baseTables
     * @return list<string>
     */
    private function orderBaseTablesForForeignKeys(string $dbName, array $baseTables): array
    {
        if ($baseTables === []) {
            return [];
        }

        $set = array_flip($baseTables);
        $rows = DB::select(
            'SELECT DISTINCT TABLE_NAME AS c, REFERENCED_TABLE_NAME AS p
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ?
               AND REFERENCED_TABLE_SCHEMA = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$dbName, $dbName]
        );

        $graph = [];
        $in = array_fill_keys($baseTables, 0);

        foreach ($rows as $row) {
            $r = (array) $row;
            $child = (string) ($r['c'] ?? '');
            $parent = (string) ($r['p'] ?? '');
            if ($child === '' || $parent === '' || $child === $parent) {
                continue;
            }
            if (! isset($set[$child], $set[$parent])) {
                continue;
            }
            if (! isset($graph[$parent])) {
                $graph[$parent] = [];
            }
            $graph[$parent][] = $child;
            $in[$child]++;
        }

        $queue = [];
        foreach ($baseTables as $t) {
            if ($in[$t] === 0) {
                $queue[] = $t;
            }
        }
        sort($queue);

        $ordered = [];
        while ($queue !== []) {
            $t = array_shift($queue);
            $ordered[] = $t;
            foreach ($graph[$t] ?? [] as $child) {
                $in[$child]--;
                if ($in[$child] === 0) {
                    $queue[] = $child;
                }
            }
            sort($queue);
        }

        foreach ($baseTables as $t) {
            if (! in_array($t, $ordered, true)) {
                $ordered[] = $t;
            }
        }

        return $ordered;
    }

    /**
     * @return list<array{type: string, name: string}>
     */
    private function listTables(string $dbName): array
    {
        $rows = DB::select(
            'SELECT TABLE_NAME AS n, TABLE_TYPE AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_TYPE, TABLE_NAME',
            [$dbName]
        );

        $out = [];
        foreach ($rows as $row) {
            $r = (array) $row;
            $name = (string) ($r['n'] ?? '');
            $type = (string) ($r['t'] ?? '');
            if ($name === '') {
                continue;
            }
            $out[] = ['type' => $type, 'name' => $name];
        }

        return $out;
    }

    private function dumpBaseTable($handle, PDO $pdo, string $table): void
    {
        $q = $this->quoteIdentifier($table);
        $createRow = DB::selectOne('SHOW CREATE TABLE '.$q);
        if ($createRow === null) {
            throw new \RuntimeException('SHOW CREATE TABLE failed for '.$table);
        }
        $cr = (array) $createRow;
        $ddl = $cr['Create Table'] ?? $cr['Create table'] ?? null;
        if (! is_string($ddl) || $ddl === '') {
            throw new \RuntimeException('Missing CREATE TABLE DDL for '.$table);
        }

        fwrite($handle, "\n-- ----------------------------\n-- Table structure `{$table}`\n-- ----------------------------\n");
        fwrite($handle, 'DROP TABLE IF EXISTS '.$q.";\n");
        fwrite($handle, $ddl.";\n\n");

        $columns = $this->getColumnNames($table);
        if ($columns === []) {
            return;
        }

        fwrite($handle, "-- ----------------------------\n-- Data for `{$table}`\n-- ----------------------------\n");

        $colList = implode(', ', array_map(fn (string $c) => $this->quoteIdentifier($c), $columns));
        $batchSize = 500;
        $offset = 0;

        while (true) {
            $rows = DB::select('SELECT * FROM '.$q.' LIMIT '.(int) $batchSize.' OFFSET '.(int) $offset);
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $values = [];
                foreach ($columns as $col) {
                    $values[] = $this->formatSqlValue($pdo, $this->getRowValue($row, $col));
                }
                fwrite(
                    $handle,
                    'INSERT INTO '.$q.' ('.$colList.') VALUES ('.implode(', ', $values).");\n"
                );
            }

            $offset += $batchSize;
            if (count($rows) < $batchSize) {
                break;
            }
        }

        fwrite($handle, "\n");
    }

    private function getRowValue(object $row, string $col): mixed
    {
        if (property_exists($row, $col)) {
            return $row->{$col};
        }

        $arr = (array) $row;
        foreach ($arr as $k => $v) {
            if (strcasecmp((string) $k, $col) === 0) {
                return $v;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function getColumnNames(string $table): array
    {
        $q = $this->quoteIdentifier($table);
        $rows = DB::select('SHOW COLUMNS FROM '.$q);
        $cols = [];
        foreach ($rows as $r) {
            $r = (array) $r;
            $field = $r['Field'] ?? null;
            if (is_string($field) && $field !== '') {
                $cols[] = $field;
            }
        }

        return $cols;
    }

    private function dumpView($handle, string $view): void
    {
        $q = $this->quoteIdentifier($view);
        try {
            $createRow = DB::selectOne('SHOW CREATE VIEW '.$q);
        } catch (\Throwable) {
            return;
        }
        if ($createRow === null) {
            return;
        }
        $cr = (array) $createRow;
        $ddl = $cr['Create View'] ?? $cr['Create view'] ?? null;
        if (! is_string($ddl) || $ddl === '') {
            return;
        }

        fwrite($handle, "\n-- ----------------------------\n-- View `{$view}`\n-- ----------------------------\n");
        fwrite($handle, 'DROP VIEW IF EXISTS '.$q.";\n");
        fwrite($handle, $ddl.";\n\n");
    }

    private function writeHeader($handle): void
    {
        $app = config('app.name', 'Laravel');
        $conn = config('database.connections.'.config('database.default'));
        $host = is_array($conn) ? (string) ($conn['host'] ?? '') : '';
        fwrite($handle, "-- SCC HazTrack SQL dump (PHP generator)\n");
        fwrite($handle, '-- Host: '.$this->safeComment($host)."\n");
        fwrite($handle, '-- Generated: '.gmdate('Y-m-d H:i:s')." UTC\n");
        fwrite($handle, '-- Application: '.$this->safeComment((string) $app)."\n\n");
    }

    private function safeComment(string $s): string
    {
        return str_replace(["\n", "\r"], ' ', $s);
    }

    private function quoteIdentifier(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    private function formatSqlValue(PDO $pdo, mixed $v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            if (is_nan($v) || is_infinite($v)) {
                return 'NULL';
            }

            return rtrim(rtrim(sprintf('%.15F', $v), '0'), '.');
        }
        if ($v instanceof \DateTimeInterface) {
            return $pdo->quote($v->format('Y-m-d H:i:s'));
        }
        if ($v instanceof \Stringable) {
            return $pdo->quote((string) $v);
        }
        if (is_string($v)) {
            return $pdo->quote($v);
        }
        if (is_resource($v)) {
            return $pdo->quote((string) stream_get_contents($v));
        }

        if (is_array($v) || is_object($v)) {
            $j = json_encode($v, JSON_UNESCAPED_UNICODE);
            return $pdo->quote($j !== false ? $j : '');
        }

        return $pdo->quote((string) $v);
    }
}

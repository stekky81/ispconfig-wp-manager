<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * After `wp search-replace`: the serialized values that still mention the old site, mostly
 * objects of plugin classes that WP-CLI skipped, go through SerializedReplacer. Runs as the
 * site user with the mysql client; values travel as hex, so no text of the site becomes SQL.
 */
final class ObjectReplacer
{
    private const TEXT_TYPES = ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'];
    private const NUMBER_TYPES = ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'];
    /** Columns WP-CLI leaves alone, and so does this. */
    private const SKIPPED_COLUMNS = ['guid'];

    public function __construct(private readonly SiteDatabase $database)
    {
    }

    /** @return int values changed */
    public function run(array $site, SearchReplacePlan $plan, Runner $runner): int
    {
        $tables = self::parseSchema($this->database->query($site, self::schemaSql(), $runner));
        $select = self::selectSql($tables, $plan->needles());
        if ($select === '') {
            return 0;
        }
        $updates = self::updates($tables, $this->database->query($site, $select, $runner), $plan);
        if ($updates !== []) {
            $this->database->query($site, implode("\n", $updates) . "\n", $runner);
        }
        return count($updates);
    }

    public static function schemaSql(): string
    {
        $types = implode(',', array_map(static fn(string $type): string => "'{$type}'", array_merge(self::TEXT_TYPES, self::NUMBER_TYPES)));
        return "SELECT HEX(c.TABLE_NAME), HEX(c.COLUMN_NAME), c.DATA_TYPE, c.COLUMN_KEY = 'PRI'"
            . ' FROM information_schema.COLUMNS c JOIN information_schema.TABLES t'
            . ' ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME'
            . " WHERE c.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE'"
            . " AND (c.COLUMN_KEY = 'PRI' OR c.DATA_TYPE IN ({$types}))"
            . ' ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION;' . "\n";
    }

    /**
     * Tables with a primary key, which says what to update, and the text columns outside it.
     * A key of another type than numbers and text is not used: those tables are left alone.
     *
     * @return array<string, array{keys: list<array{string, bool}>, columns: list<string>}> keys: name, numeric
     */
    public static function parseSchema(string $output): array
    {
        $tables = [];
        $unusable = [];
        foreach (explode("\n", trim($output)) as $line) {
            $cells = explode("\t", $line);
            if (count($cells) !== 4 || !ctype_xdigit($cells[0]) || !ctype_xdigit($cells[1])) {
                continue;
            }
            [$table, $column] = [(string)hex2bin($cells[0]), (string)hex2bin($cells[1])];
            $type = strtolower($cells[2]);
            $tables[$table] ??= ['keys' => [], 'columns' => []];
            if ($cells[3] === '1') {
                if (in_array($type, self::NUMBER_TYPES, true) || in_array($type, self::TEXT_TYPES, true)) {
                    $tables[$table]['keys'][] = [$column, in_array($type, self::NUMBER_TYPES, true)];
                } else {
                    $unusable[$table] = true;
                }
            } elseif (in_array($type, self::TEXT_TYPES, true) && !in_array(strtolower($column), self::SKIPPED_COLUMNS, true)) {
                $tables[$table]['columns'][] = $column;
            }
        }
        return array_filter(
            $tables,
            static fn(array $t, string $name): bool => $t['keys'] !== [] && $t['columns'] !== [] && !isset($unusable[$name]),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * One SELECT for each column: serialized values that mention one of the needles.
     *
     * @param array<string, array{keys: list<array{string, bool}>, columns: list<string>}> $tables
     * @param list<string> $needles
     */
    public static function selectSql(array $tables, array $needles): string
    {
        $likes = [];
        foreach ($needles as $needle) {
            // Domains and ISPConfig paths: anything else is not searched rather than escaped.
            if (preg_match('#^[A-Za-z0-9./_-]{4,}$#', $needle)) {
                $likes[] = "'%" . str_replace('_', '\\_', $needle) . "%'";
            }
        }
        if ($likes === []) {
            return '';
        }
        $sql = '';
        $t = 0;
        foreach ($tables as $table => $info) {
            // HEX() of a number gives the number in hex, not its digits.
            $keys = implode(', ', array_map(
                static fn(array $key): string => $key[1] ? 'HEX(CAST(' . SiteDatabase::quoteName($key[0]) . ' AS CHAR))' : 'HEX(' . SiteDatabase::quoteName($key[0]) . ')',
                $info['keys'],
            ));
            foreach ($info['columns'] as $c => $column) {
                $col = SiteDatabase::quoteName($column);
                $mentions = implode(' OR ', array_map(static fn(string $like): string => "{$col} LIKE {$like}", $likes));
                $sql .= "SELECT {$t}, {$c}, {$keys}, HEX({$col}) FROM " . SiteDatabase::quoteName($table)
                    . " WHERE ({$col} LIKE 'a:%' OR {$col} LIKE 'O:%' OR {$col} LIKE 's:%') AND ({$mentions});\n";
            }
            $t++;
        }
        return $sql;
    }

    /**
     * An UPDATE for each value that changes. It also names the old value, so a value that changed
     * in the meantime is left alone.
     *
     * @param array<string, array{keys: list<array{string, bool}>, columns: list<string>}> $tables
     * @return list<string>
     */
    public static function updates(array $tables, string $rows, SearchReplacePlan $plan): array
    {
        $names = array_keys($tables);
        $replacer = new SerializedReplacer($plan->apply(...));
        $updates = [];
        foreach (explode("\n", trim($rows)) as $line) {
            $cells = explode("\t", $line);
            $table = $names[(int)($cells[0] ?? -1)] ?? null;
            if ($table === null || !ctype_digit($cells[0]) || !ctype_digit($cells[1] ?? '')) {
                continue;
            }
            $info = $tables[$table];
            $column = $info['columns'][(int)$cells[1]] ?? null;
            if ($column === null || count($cells) !== 3 + count($info['keys'])) {
                continue;
            }
            $hex = (string)array_pop($cells);
            if (!ctype_xdigit($hex)) {
                continue;
            }
            $where = self::keyCondition($info['keys'], array_slice($cells, 2));
            $old = (string)hex2bin($hex);
            $new = $replacer->replace($old);
            if ($where === null || $new === null || $new === $old) {
                continue;
            }
            $col = SiteDatabase::quoteName($column);
            $updates[] = 'UPDATE ' . SiteDatabase::quoteName($table) . " SET {$col} = X'" . bin2hex($new) . "'"
                . " WHERE {$where} AND {$col} = X'" . bin2hex($old) . "';";
        }
        return $updates;
    }

    /**
     * @param list<array{string, bool}> $keys
     * @param list<string> $values hex
     */
    private static function keyCondition(array $keys, array $values): ?string
    {
        $parts = [];
        foreach ($keys as $i => [$name, $numeric]) {
            $hex = $values[$i] ?? '';
            if (!ctype_xdigit($hex)) {
                return null;
            }
            $value = (string)hex2bin($hex);
            if ($numeric) {
                // A number compared to a hex literal would be compared to the number of the hex.
                if (!preg_match('/^-?\d{1,20}$/', $value)) {
                    return null;
                }
                $parts[] = SiteDatabase::quoteName($name) . ' = ' . $value;
            } else {
                $parts[] = SiteDatabase::quoteName($name) . " = X'" . $hex . "'";
            }
        }
        return implode(' AND ', $parts);
    }
}

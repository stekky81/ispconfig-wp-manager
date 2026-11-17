<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WPStation;

/**
 * The tables wpstation_transfer and wpstation_transfer_piece in the master database, with a connection of its own:
 * prepared statements carry the binary pieces as they are.
 */
final class MysqlTransferStore implements TransferStore
{
    private const STATES = ['requested', 'sending', 'sent', 'failed'];

    private \mysqli $db;

    public function __construct(string $host, string $user, string $password, string $database, int $port = 3306)
    {
        // No mysqli_report(): it is global and would change the connection of ISPConfig in the same process.
        $db = mysqli_init();
        if ($db === false || !@$db->real_connect($host, $user, $password, $database, $port)) {
            throw new \RuntimeException('Cannot connect to the master database for the transfer');
        }
        $db->set_charset('utf8mb4');
        $this->db = $db;
    }

    public function open(int $sourceSiteId, int $targetSiteId, int $sourceServerId, int $targetServerId): int
    {
        $this->run(
            'INSERT INTO wpstation_transfer (source_site_id, target_site_id, source_server_id, target_server_id, state) VALUES (?, ?, ?, ?, ?)',
            'iiiis',
            [$sourceSiteId, $targetSiteId, $sourceServerId, $targetServerId, 'requested'],
        );
        return (int)$this->db->insert_id;
    }

    public function claim(int $sourceServerId): ?array
    {
        $rows = $this->rows(
            "SELECT transfer_id, source_site_id, target_site_id FROM wpstation_transfer WHERE source_server_id = ? AND state = 'requested' ORDER BY transfer_id",
            'i',
            [$sourceServerId],
        );
        foreach ($rows as $row) {
            // Only one worker may send it.
            // Counted on the statement: the connection does not report it for prepared statements.
            if ($this->run("UPDATE wpstation_transfer SET state = 'sending' WHERE transfer_id = ? AND state = 'requested'", 'i', [(int)$row['transfer_id']]) === 1) {
                return array_map('intval', $row);
            }
        }
        return null;
    }

    public function state(int $transferId): ?array
    {
        $rows = $this->rows('SELECT state, error FROM wpstation_transfer WHERE transfer_id = ?', 'i', [$transferId]);
        return $rows === [] ? null : ['state' => (string)$rows[0]['state'], 'error' => (string)$rows[0]['error']];
    }

    public function setState(int $transferId, string $state, string $error = ''): void
    {
        if (!in_array($state, self::STATES, true)) {
            throw new \InvalidArgumentException("Unknown transfer state: {$state}");
        }
        $this->run('UPDATE wpstation_transfer SET state = ?, error = ? WHERE transfer_id = ?', 'ssi', [$state, mb_substr($error, 0, 1000), $transferId]);
    }

    public function put(int $transferId, int $seq, string $data): void
    {
        $this->run('INSERT INTO wpstation_transfer_piece (transfer_id, seq, data) VALUES (?, ?, ?)', 'iis', [$transferId, $seq, $data]);
    }

    public function pending(int $transferId): int
    {
        return (int)($this->rows('SELECT COUNT(*) AS n FROM wpstation_transfer_piece WHERE transfer_id = ?', 'i', [$transferId])[0]['n'] ?? 0);
    }

    public function take(int $transferId, int $seq): ?string
    {
        $rows = $this->rows('SELECT data FROM wpstation_transfer_piece WHERE transfer_id = ? AND seq = ?', 'ii', [$transferId, $seq]);
        if ($rows === []) {
            return null;
        }
        $this->run('DELETE FROM wpstation_transfer_piece WHERE transfer_id = ? AND seq = ?', 'ii', [$transferId, $seq]);
        return (string)$rows[0]['data'];
    }

    public function close(int $transferId): void
    {
        $this->run('DELETE FROM wpstation_transfer_piece WHERE transfer_id = ?', 'i', [$transferId]);
        $this->run('DELETE FROM wpstation_transfer WHERE transfer_id = ?', 'i', [$transferId]);
    }

    public function prune(int $olderThan): void
    {
        foreach ($this->rows('SELECT transfer_id FROM wpstation_transfer WHERE created < NOW() - INTERVAL ? SECOND', 'i', [$olderThan]) as $row) {
            $this->close((int)$row['transfer_id']);
        }
    }

    /**
     * @param list<mixed> $values
     * @return int the rows changed
     */
    private function run(string $sql, string $types, array $values): int
    {
        $statement = $this->execute($sql, $types, $values);
        $changed = (int)$statement->affected_rows;
        $statement->close();
        return $changed;
    }

    /** @param list<mixed> $values */
    private function execute(string $sql, string $types, array $values): \mysqli_stmt
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false || !$statement->bind_param($types, ...$values) || !$statement->execute()) {
            throw new \RuntimeException('Transfer table: ' . $this->db->error);
        }
        return $statement;
    }

    /**
     * @param list<mixed> $values
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, string $types, array $values): array
    {
        $statement = $this->execute($sql, $types, $values);
        $result = $statement->get_result();
        $rows = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
        $statement->close();
        return $rows;
    }
}

<?php

declare(strict_types=1);

namespace Mercurius\Memory\Infrastructure;

use Amp\Postgres\PostgresLink;
use LengthException;
use Mercurius\Memory\Domain\Probe;
use Mercurius\Memory\Domain\Probes;

final class PostgresProbes implements Probes {
    public function __construct(private readonly PostgresLink $db) {}

    public function all(): array {
        $probes = [];
        foreach ($this->db->query('SELECT id, question, fact_id, missing FROM memory_probes ORDER BY id') as $row) {
            $probes[] = self::probe($row);
        }
        return $probes;
    }

    public function add(string $question, int $factId): Probe {
        // the check and the insert in one statement: two owners' commands at once do not pass the limit together
        $row = $this->db->execute(
            'INSERT INTO memory_probes (question, fact_id) SELECT ?, ? WHERE (SELECT count(*) FROM memory_probes) < ? RETURNING id, question, fact_id, missing',
            [$question, $factId, self::MAX],
        )->fetchRow();
        if ($row === null) {
            throw new LengthException('the list of probes is full');
        }
        return self::probe($row);
    }

    public function remove(int $id): bool {
        return $this->db->execute('DELETE FROM memory_probes WHERE id = ? RETURNING id', [$id])->fetchRow() !== null;
    }

    public function mark(int $id, bool $missing): void {
        $this->db->execute('UPDATE memory_probes SET missing = ? WHERE id = ?', [$missing, $id]);
    }

    /** @param array<string, mixed> $row */
    private static function probe(array $row): Probe {
        return new Probe(
            isset($row['id']) && is_int($row['id']) ? $row['id'] : 0,
            isset($row['question']) && is_string($row['question']) ? $row['question'] : '',
            isset($row['fact_id']) && is_int($row['fact_id']) ? $row['fact_id'] : 0,
            ($row['missing'] ?? null) === true,
        );
    }
}

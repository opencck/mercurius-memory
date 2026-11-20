<?php

declare(strict_types=1);

namespace Mercurius\Memory\Infrastructure;

use Amp\Postgres\PostgresLink;
use Mercurius\Memory\Domain\AliasStore;

final class PostgresAliasStore implements AliasStore {
    public function __construct(private readonly PostgresLink $db) {}

    public function groups(): array {
        $groups = [];
        foreach ($this->db->query('SELECT id, array_to_json(words)::text AS words FROM memory_aliases ORDER BY id') as $row) {
            $id = $row['id'] ?? null;
            $raw = $row['words'] ?? null;
            /** @var mixed $words */
            $words = is_string($raw) ? json_decode($raw, true, 4) : null;
            if (!is_int($id) || !is_array($words)) {
                continue;
            }
            $groups[$id] = array_values(array_filter($words, is_string(...)));
        }
        return $groups;
    }

    public function add(array $words, ?int $by): int {
        $row = $this->db->execute('INSERT INTO memory_aliases (words, added_by) VALUES (?::text[], ?) RETURNING id', [$words, $by])->fetchRow();
        $id = $row['id'] ?? null;
        return is_int($id) ? $id : 0;
    }

    public function replace(int $id, array $words): bool {
        return $this->db->execute('UPDATE memory_aliases SET words = ?::text[] WHERE id = ?', [$words, $id])->getRowCount() > 0;
    }

    public function remove(int $id): bool {
        return $this->db->execute('DELETE FROM memory_aliases WHERE id = ?', [$id])->getRowCount() > 0;
    }
}

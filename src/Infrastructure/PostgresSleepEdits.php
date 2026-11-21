<?php

declare(strict_types=1);

namespace Mercurius\Memory\Infrastructure;

use Amp\Postgres\PostgresLink;
use Mercurius\Memory\Domain\SleepEdit;
use Mercurius\Memory\Domain\SleepEdits;
use UnexpectedValueException;

final class PostgresSleepEdits implements SleepEdits {
    public function __construct(private readonly PostgresLink $db) {}

    public function record(string $kind, ?int $kept, ?int $keptVersion, int $ended, int $endedChars): int {
        $row = $this->db->execute(
            'INSERT INTO memory_sleep_edits (kind, kept, kept_version, ended, ended_chars) VALUES (?, ?, ?, ?, ?) RETURNING id',
            [$kind, $kept, $keptVersion, $ended, $endedChars],
        )->fetchRow();
        $id = $row['id'] ?? null;
        return is_int($id) ? $id : throw new UnexpectedValueException('Sleep edit was not recorded');
    }

    public function find(int $id): ?SleepEdit {
        $row = $this->db->execute('SELECT id, kind, kept, kept_version, ended, undone_at IS NOT NULL AS undone FROM memory_sleep_edits WHERE id = ?', [$id])->fetchRow();
        if ($row === null) {
            return null;
        }
        $int = static fn(string $k): ?int => isset($row[$k]) && is_int($row[$k]) ? $row[$k] : null;
        $kind = $row['kind'] ?? null;
        return new SleepEdit($id, is_string($kind) ? $kind : '', $int('kept'), $int('kept_version'), $int('ended'), ($row['undone'] ?? false) === true);
    }

    public function undo(int $id): bool {
        return $this->db->execute('UPDATE memory_sleep_edits SET undone_at = now() WHERE id = ? AND undone_at IS NULL RETURNING id', [$id])->fetchRow() !== null;
    }

    public function since(int $at): array {
        $row = $this->db->execute(
            'SELECT count(*)::int AS facts, coalesce(sum(ended_chars), 0)::int AS chars FROM memory_sleep_edits WHERE undone_at IS NULL AND created_at >= to_timestamp(?)',
            [$at],
        )->fetchRow();
        $facts = $row['facts'] ?? 0;
        $chars = $row['chars'] ?? 0;
        return [is_int($facts) ? $facts : 0, is_int($chars) ? $chars : 0];
    }
}

<?php

declare(strict_types=1);

namespace Mercurius\Memory\Infrastructure;

use Amp\Postgres\PostgresLink;
use Mercurius\Memory\Domain\GuardJournal;

final class PostgresGuardJournal implements GuardJournal {
    public function __construct(private readonly PostgresLink $db) {}

    public function last(): ?array {
        $row = $this->db->execute('SELECT extract(epoch FROM checked_at)::bigint AS at, facts, chars FROM memory_guard ORDER BY id DESC LIMIT 1')->fetchRow();
        if ($row === null) {
            return null;
        }
        $at = $row['at'] ?? null;
        $facts = $row['facts'] ?? null;
        $chars = $row['chars'] ?? null;
        return ['at' => is_int($at) ? $at : 0, 'facts' => is_int($facts) ? $facts : 0, 'chars' => is_int($chars) ? $chars : 0];
    }

    public function save(int $facts, int $chars, bool $alerted): void {
        $this->db->execute('INSERT INTO memory_guard (facts, chars, alerted) VALUES (?, ?, ?)', [$facts, $chars, $alerted]);
    }
}

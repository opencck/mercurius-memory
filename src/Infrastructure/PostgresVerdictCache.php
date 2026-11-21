<?php

declare(strict_types=1);

namespace Mercurius\Memory\Infrastructure;

use Amp\Postgres\PostgresLink;
use Mercurius\Memory\Domain\Verdict;
use Mercurius\Memory\Domain\VerdictCache;

final class PostgresVerdictCache implements VerdictCache {
    public function __construct(private readonly PostgresLink $db) {}

    public function find(string $key): ?Verdict {
        $row = $this->db->execute('SELECT label, confidence, newer FROM memory_sleep_verdicts WHERE key = ?', [$key])->fetchRow();
        if ($row === null) {
            return null;
        }
        $label = $row['label'] ?? null;
        $confidence = $row['confidence'] ?? null;
        $newer = $row['newer'] ?? null;
        return is_string($label) && is_numeric($confidence) ? Verdict::of($label, (float) $confidence, is_string($newer) ? $newer : null) : null;
    }

    public function keep(string $key, int $a, int $b, Verdict $verdict): void {
        $this->db->execute(
            'INSERT INTO memory_sleep_verdicts (key, fact_a, fact_b, label, confidence, newer) VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (key) DO UPDATE SET label = EXCLUDED.label, confidence = EXCLUDED.confidence, newer = EXCLUDED.newer, created_at = now()',
            [$key, $a, $b, $verdict->label, $verdict->confidence, $verdict->newer],
        );
    }

    public function forgetFact(int $factId): void {
        $this->db->execute('DELETE FROM memory_sleep_verdicts WHERE fact_a = ? OR fact_b = ?', [$factId, $factId]);
    }
}

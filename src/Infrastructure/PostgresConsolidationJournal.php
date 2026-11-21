<?php

declare(strict_types=1);

namespace Mercurius\Memory\Infrastructure;

use Amp\Postgres\PostgresLink;
use Mercurius\Memory\Domain\ConsolidationJournal;

final class PostgresConsolidationJournal implements ConsolidationJournal {
    public function __construct(private readonly PostgresLink $db) {}

    public function last(): ?array {
        $row = $this->db->execute(
            "SELECT extract(epoch FROM started_at)::bigint AS at, facts, chars, status FROM memory_consolidations WHERE status <> 'failed' ORDER BY id DESC LIMIT 1",
        )->fetchRow();
        if ($row === null) {
            return null;
        }
        $at = $row['at'] ?? null;
        $facts = $row['facts'] ?? null;
        $chars = $row['chars'] ?? null;
        $status = $row['status'] ?? null;
        return [
            'at' => is_int($at) ? $at : 0,
            'facts' => is_int($facts) ? $facts : 0,
            'chars' => is_int($chars) ? $chars : 0,
            'status' => is_string($status) ? $status : '',
        ];
    }

    public function redact(string $text, string $replacement): int {
        if ($text === '') {
            return 0;
        }
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $from = substr(json_encode($text, $flags), 1, -1);
        $to = substr(json_encode($replacement, $flags), 1, -1);
        $changed = 0;
        foreach ($this->db->execute(
            'UPDATE memory_consolidations SET report = replace(report::text, ?, ?)::jsonb WHERE strpos(report::text, ?) > 0 RETURNING id',
            [$from, $to, $from],
        ) as $row) {
            $changed++;
        }
        return $changed;
    }

    public function save(string $status, int $facts, int $chars, int $durationMs, array $report, int $modelCalls = 0): void {
        $this->db->execute(
            'INSERT INTO memory_consolidations (status, facts, chars, duration_ms, report, model_calls) VALUES (?, ?, ?, ?, ?::jsonb, ?)',
            [$status, $facts, $chars, $durationMs, json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $modelCalls],
        );
    }

    public function due(string $kind, int $a, int $b, int $pauseDays): bool {
        $row = $this->db->execute(
            'SELECT 1 FROM memory_notices WHERE kind = ? AND a = ? AND b = ? AND shown_at >= now() - make_interval(days => ?)',
            [$kind, $a, $b, $pauseDays],
        )->fetchRow();
        return $row === null;
    }

    public function shown(string $kind, int $a, int $b): void {
        $this->db->execute(
            'INSERT INTO memory_notices (kind, a, b) VALUES (?, ?, ?) ON CONFLICT (kind, a, b) DO UPDATE SET shown_at = now()',
            [$kind, $a, $b],
        );
    }
}

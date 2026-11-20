<?php

declare(strict_types=1);

namespace Mercurius\Memory\Infrastructure;

use Closure;
use Mercurius\Memory\Domain\ConsolidationJournal;

final class InMemoryConsolidationJournal implements ConsolidationJournal {
    /** @var list<array{at: int, facts: int, chars: int, status: string, report: list<string>}> */
    public array $passes = [];
    /** @var array<string, int> "kind:a:b" => shown at */
    public array $notices = [];
    /** @var Closure(): int */
    public Closure $now;

    public function __construct() {
        $this->now = static fn(): int => time();
    }

    public function last(): ?array {
        foreach (array_reverse($this->passes) as $pass) {
            if ($pass['status'] !== self::FAILED) {
                return ['at' => $pass['at'], 'facts' => $pass['facts'], 'chars' => $pass['chars'], 'status' => $pass['status']];
            }
        }
        return null;
    }

    public function redact(string $text, string $replacement): int {
        $changed = 0;
        $passes = [];
        foreach ($this->passes as $pass) {
            $report = array_map(static fn(string $line): string => str_replace($text, $replacement, $line), $pass['report']);
            if ($text !== '' && $report !== $pass['report']) {
                $pass['report'] = $report;
                $changed++;
            }
            $passes[] = $pass;
        }
        $this->passes = $passes;
        return $changed;
    }

    public function save(string $status, int $facts, int $chars, int $durationMs, array $report): void {
        $this->passes[] = ['at' => ($this->now)(), 'facts' => $facts, 'chars' => $chars, 'status' => $status, 'report' => $report];
    }

    public function due(string $kind, int $a, int $b, int $pauseDays): bool {
        $key = $kind . ':' . $a . ':' . $b;
        return !isset($this->notices[$key]) || $this->notices[$key] < ($this->now)() - $pauseDays * 86400;
    }

    public function shown(string $kind, int $a, int $b): void {
        $this->notices[$kind . ':' . $a . ':' . $b] = ($this->now)();
    }
}

<?php

declare(strict_types=1);

namespace Mercurius\Memory\Domain;

/**
 * What the nightly check of memory remembers between nights: each pass with a snapshot of memory, and what was
 * already shown to the owner.
 *
 * spec: brief/memory.md#консолидация-dreaming
 */
interface ConsolidationJournal {
    public const SKIPPED = 'skipped';
    public const DONE = 'done';
    public const FAILED = 'failed';

    /** @return array{at: int, facts: int, chars: int, status: string}|null the last pass that looked at memory */
    public function last(): ?array;

    /**
     * @param list<string> $report
     * @param int $modelCalls calls of the model of sleep in this pass
     */
    public function save(string $status, int $facts, int $chars, int $durationMs, array $report, int $modelCalls = 0): void;

    /** Whether a finding may be shown now: not shown within the pause. */
    public function due(string $kind, int $a, int $b, int $pauseDays): bool;

    /** A finding reached the owner: the pause counts from now. */
    public function shown(string $kind, int $a, int $b): void;

    /** Replaces a text in every stored report (a forgotten fact); returns the reports changed. */
    public function redact(string $text, string $replacement): int;
}

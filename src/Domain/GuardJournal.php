<?php

declare(strict_types=1);

namespace Mercurius\Memory\Domain;

/**
 * The snapshots of the guard against a loss of memory: how many active facts and characters there were at each check.
 *
 * spec: brief/memory.md#страж-потерь
 */
interface GuardJournal {
    /** @return array{at: int, facts: int, chars: int}|null the last snapshot */
    public function last(): ?array;

    public function save(int $facts, int $chars, bool $alerted): void;
}

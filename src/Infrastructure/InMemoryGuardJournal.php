<?php

declare(strict_types=1);

namespace Mercurius\Memory\Infrastructure;

use Closure;
use Mercurius\Memory\Domain\GuardJournal;

final class InMemoryGuardJournal implements GuardJournal {
    /** @var list<array{at: int, facts: int, chars: int, alerted: bool}> */
    public array $checks = [];
    /** @var (Closure(): int)|null */
    public ?Closure $now = null;

    public function last(): ?array {
        $last = end($this->checks);
        return $last === false ? null : ['at' => $last['at'], 'facts' => $last['facts'], 'chars' => $last['chars']];
    }

    public function save(int $facts, int $chars, bool $alerted): void {
        $this->checks[] = ['at' => $this->now === null ? time() : ($this->now)(), 'facts' => $facts, 'chars' => $chars, 'alerted' => $alerted];
    }
}

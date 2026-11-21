<?php

declare(strict_types=1);

namespace Mercurius\Memory\Infrastructure;

use Mercurius\Memory\Domain\Verdict;
use Mercurius\Memory\Domain\VerdictCache;

final class InMemoryVerdictCache implements VerdictCache {
    /** @var array<string, array{int, int, Verdict}> */
    public array $verdicts = [];

    public function find(string $key): ?Verdict {
        return $this->verdicts[$key][2] ?? null;
    }

    public function keep(string $key, int $a, int $b, Verdict $verdict): void {
        $this->verdicts[$key] = [$a, $b, $verdict];
    }

    public function forgetFact(int $factId): void {
        $this->verdicts = array_filter($this->verdicts, static fn(array $v): bool => $v[0] !== $factId && $v[1] !== $factId);
    }
}

<?php

declare(strict_types=1);

namespace Mercurius\Memory\Domain;

/**
 * Verdicts already paid for: a pair is not asked again while its texts and the prompt are the same.
 *
 * spec: brief/memory.md#судья-пары
 */
interface VerdictCache {
    public function find(string $key): ?Verdict;

    public function keep(string $key, int $a, int $b, Verdict $verdict): void;

    /** A forgotten fact leaves the cache too. */
    public function forgetFact(int $factId): void;
}

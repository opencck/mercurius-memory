<?php

declare(strict_types=1);

namespace Mercurius\Memory\Domain;

/**
 * The model of sleep asked about pairs of facts the code could not settle.
 *
 * spec: brief/memory.md#судья-пары
 */
interface SleepJudge {
    /**
     * @param list<array{Fact, Fact}> $pairs
     * @return list<?Verdict> by the order of the pairs; null — no usable answer for that pair
     */
    public function judge(array $pairs): array;

    /** @return array{int, int, float} calls, tokens and dollars spent since the last time this was asked */
    public function spent(): array;
}

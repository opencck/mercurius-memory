<?php

declare(strict_types=1);

namespace Mercurius\Memory\Domain;

/**
 * The model of sleep asked to write one text for two facts where one adds a detail to the other.
 *
 * spec: brief/memory.md#слияние
 */
interface SleepMerger {
    /**
     * @param list<array{Fact, Fact}> $pairs the fact that stays first: the text keeps its language and script
     * @return list<?string> by the order of the pairs; null — no text for that pair
     */
    public function merge(array $pairs): array;

    /** @return array{int, int, float} calls, tokens and dollars spent since the last time this was asked */
    public function spent(): array;
}

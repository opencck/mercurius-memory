<?php

declare(strict_types=1);

namespace Mercurius\Memory\Domain;

/**
 * The model of sleep asked for words a fact may be asked about by and which are not in its text.
 *
 * spec: brief/memory.md#ночное-обогащение
 */
interface SleepEnricher {
    /**
     * @param list<Fact> $facts
     * @param bool $translate also translations into the other languages of memory
     * @return list<?array{list<string>, list<list<string>>}> by the order of the facts: the words, and pairs of one word in
     *         two spellings; null — no usable answer for that fact
     */
    public function enrich(array $facts, bool $translate): array;

    /** @return array{int, int, float} calls, tokens and dollars spent since the last time this was asked */
    public function spent(): array;
}

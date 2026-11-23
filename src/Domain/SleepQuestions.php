<?php

declare(strict_types=1);

namespace Mercurius\Memory\Domain;

/**
 * The model of sleep writes questions to a fact that the fact answers, without the fact's own words: what a person
 * would ask when they do not remember how the fact was put.
 *
 * spec: brief/memory.md#самопроверка-поиска
 */
interface SleepQuestions {
    /**
     * @param list<Fact> $facts
     * @return list<list<string>> by the order of the facts; [] — none for that fact
     */
    public function questions(array $facts): array;

    /** @return array{int, int, float} calls, tokens and dollars spent since the last time this was asked */
    public function spent(): array;
}

<?php

declare(strict_types=1);

namespace Mercurius\Memory\Domain;

/**
 * The model of sleep reads the talk of a day in a place and writes the facts of that place worth keeping.
 *
 * spec: brief/memory.md#сон-проекта
 */
interface SleepNotes {
    /**
     * @param string $talk the messages of the day in the place, oldest first
     * @param list<Fact> $known the facts of the place already: not to be written again
     * @return list<array{string, list<string>}> new facts and words to find each by
     */
    public function notes(string $talk, array $known): array;

    /** @return array{int, int, float} calls, tokens and dollars spent since the last time this was asked */
    public function spent(): array;
}

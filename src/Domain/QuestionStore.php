<?php

declare(strict_types=1);

namespace Mercurius\Memory\Domain;

/**
 * The questions of each fact, kept with the version they were written for, and how many nights with words the fact
 * was still not found by them.
 *
 * spec: brief/memory.md#самопроверка-поиска
 */
interface QuestionStore {
    /** @return list<string>|null null — none for this version of the fact */
    public function questions(int $factId, int $version): ?array;

    /** @param list<string> $questions */
    public function save(int $factId, int $version, array $questions): void;

    /** One more night with words and not found; @return int such nights in a row */
    public function miss(int $factId): int;

    /** Found: the count of misses starts again. */
    public function found(int $factId): void;
}

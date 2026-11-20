<?php

declare(strict_types=1);

namespace Mercurius\Memory\Domain;

/**
 * The owner's table of words that mean one thing: groups of words, each a key of search for all of them.
 *
 * spec: brief/memory.md#словари
 */
interface AliasStore {
    /** @return array<int, list<string>> the words of every group, by its number, oldest first */
    public function groups(): array;

    /**
     * @param list<string> $words
     * @param int|null $by who added it: a participant; null — the owner by command
     * @return int the number of the new group
     */
    public function add(array $words, ?int $by): int;

    /** @param list<string> $words the group's words as they are now */
    public function replace(int $id, array $words): bool;

    public function remove(int $id): bool;
}

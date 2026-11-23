<?php

declare(strict_types=1);

namespace Mercurius\Memory\Domain;

/**
 * The owner's probes of memory: a short list the guard asks every night.
 *
 * spec: brief/memory.md#пробы-владельца
 */
interface Probes {
    /** probes kept at most: the list is the owner's, read every night */
    public const MAX = 20;
    public const QUESTION_MAX = 300;

    /** @return list<Probe> in the order they were added */
    public function all(): array;

    /** @throws \LengthException when the list is full */
    public function add(string $question, int $factId): Probe;

    /** false when there is no such probe */
    public function remove(int $id): bool;

    public function mark(int $id, bool $missing): void;
}

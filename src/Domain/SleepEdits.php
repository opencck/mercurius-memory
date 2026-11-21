<?php

declare(strict_types=1);

namespace Mercurius\Memory\Domain;

/**
 * The journal of edits sleep made by itself.
 *
 * spec: brief/memory.md#постоянное-разрешение
 */
interface SleepEdits {
    public function record(string $kind, ?int $kept, ?int $keptVersion, int $ended, int $endedChars): int;

    public function find(int $id): ?SleepEdit;

    /** Marks an edit undone once; false when it was undone already or is not there. */
    public function undo(int $id): bool;

    /** @return array{int, int} facts and characters sleep ended since the time (unix seconds) and nobody undid */
    public function since(int $at): array;
}

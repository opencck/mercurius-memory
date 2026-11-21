<?php

declare(strict_types=1);

namespace Mercurius\Memory\Infrastructure;

use Mercurius\Memory\Domain\SleepEdit;
use Mercurius\Memory\Domain\SleepEdits;

final class InMemorySleepEdits implements SleepEdits {
    /** @var array<int, array{SleepEdit, int, int}> the edit, characters ended, when */
    public array $edits = [];
    private int $next = 1;

    public function record(string $kind, ?int $kept, ?int $keptVersion, int $ended, int $endedChars): int {
        $id = $this->next++;
        $this->edits[$id] = [new SleepEdit($id, $kind, $kept, $keptVersion, $ended, false), $endedChars, time()];
        return $id;
    }

    public function find(int $id): ?SleepEdit {
        return $this->edits[$id][0] ?? null;
    }

    public function undo(int $id): bool {
        $edit = $this->edits[$id][0] ?? null;
        if ($edit === null || $edit->undone) {
            return false;
        }
        $this->edits[$id][0] = new SleepEdit($edit->id, $edit->kind, $edit->kept, $edit->keptVersion, $edit->ended, true);
        return true;
    }

    public function since(int $at): array {
        [$facts, $chars] = [0, 0];
        foreach ($this->edits as [$edit, $ended, $when]) {
            if (!$edit->undone && $when >= $at) {
                $facts++;
                $chars += $ended;
            }
        }
        return [$facts, $chars];
    }
}

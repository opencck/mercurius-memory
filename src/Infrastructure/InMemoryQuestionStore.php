<?php

declare(strict_types=1);

namespace Mercurius\Memory\Infrastructure;

use Mercurius\Memory\Domain\QuestionStore;

final class InMemoryQuestionStore implements QuestionStore {
    /** @var array<int, array{int, list<string>}> */
    private array $questions = [];
    /** @var array<int, int> */
    private array $misses = [];

    public function questions(int $factId, int $version): ?array {
        $kept = $this->questions[$factId] ?? null;
        return $kept !== null && $kept[0] === $version ? $kept[1] : null;
    }

    public function save(int $factId, int $version, array $questions): void {
        $this->questions[$factId] = [$version, $questions];
        $this->misses[$factId] = 0;
    }

    public function miss(int $factId): int {
        return $this->misses[$factId] = ($this->misses[$factId] ?? 0) + 1;
    }

    public function found(int $factId): void {
        $this->misses[$factId] = 0;
    }
}

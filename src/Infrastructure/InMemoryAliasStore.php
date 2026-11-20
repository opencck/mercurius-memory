<?php

declare(strict_types=1);

namespace Mercurius\Memory\Infrastructure;

use Mercurius\Memory\Domain\AliasStore;

final class InMemoryAliasStore implements AliasStore {
    /** @var array<int, list<string>> */
    private array $groups = [];
    private int $next = 1;

    public function groups(): array {
        return $this->groups;
    }

    public function add(array $words, ?int $by): int {
        $this->groups[$this->next] = $words;
        return $this->next++;
    }

    public function replace(int $id, array $words): bool {
        if (!isset($this->groups[$id])) {
            return false;
        }
        $this->groups[$id] = $words;
        return true;
    }

    public function remove(int $id): bool {
        if (!isset($this->groups[$id])) {
            return false;
        }
        unset($this->groups[$id]);
        return true;
    }
}

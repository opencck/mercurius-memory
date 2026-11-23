<?php

declare(strict_types=1);

namespace Mercurius\Memory\Infrastructure;

use LengthException;
use Mercurius\Memory\Domain\Probe;
use Mercurius\Memory\Domain\Probes;

final class InMemoryProbes implements Probes {
    /** @var array<int, Probe> */
    private array $probes = [];
    private int $next = 1;

    public function all(): array {
        return array_values($this->probes);
    }

    public function add(string $question, int $factId): Probe {
        if (count($this->probes) >= self::MAX) {
            throw new LengthException('the list of probes is full');
        }
        $probe = new Probe($this->next++, $question, $factId, false);
        $this->probes[$probe->id] = $probe;
        return $probe;
    }

    public function remove(int $id): bool {
        if (!isset($this->probes[$id])) {
            return false;
        }
        unset($this->probes[$id]);
        return true;
    }

    public function mark(int $id, bool $missing): void {
        $probe = $this->probes[$id] ?? null;
        if ($probe !== null) {
            $this->probes[$id] = new Probe($probe->id, $probe->question, $probe->factId, $missing);
        }
    }
}

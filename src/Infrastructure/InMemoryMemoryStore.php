<?php

declare(strict_types=1);

namespace Mercurius\Memory\Infrastructure;

use Mercurius\Memory\Domain\Fact;
use Mercurius\Memory\Domain\Hrr;
use Mercurius\Memory\Domain\MemoryStore;
use Mercurius\Memory\Domain\Proposal;

final class InMemoryMemoryStore implements MemoryStore {
    /** @var array<int, Fact> */
    public array $facts = [];
    /** @var array<int, list<array{version: int, text: string, reason: string, source: ?string}>> fact id => its versions */
    private array $history = [];
    /** @var array<string, true> */
    public array $tombstones = [];
    /** @var array<int, Proposal> */
    public array $proposals = [];
    /** @var array<int, array{string, string}> */
    public array $cards = [];
    /** @var array<string, string> */
    public array $coreTexts = [];
    /** @var list<array{int, int}> conversation, fact */
    public array $recalls = [];
    private int $nextFact = 1;
    private int $nextProposal = 1;

    public function addFact(string $scope, string $category, string $text, array $keys, float $trust, string $provenance, ?int $runId): Fact {
        $id = $this->nextFact++;
        $this->history[$id] = [['version' => 1, 'text' => $text, 'reason' => 'добавлен', 'source' => $provenance]];
        return $this->facts[$id] = new Fact($id, $scope, $category, $text, $keys, $trust, $provenance, true, 1, time());
    }

    public function fact(int $id): ?Fact {
        return $this->facts[$id] ?? null;
    }

    public function similarities(array $factIds, array $phasor): array {
        $found = [];
        foreach ($factIds as $id) {
            if (isset($this->facts[$id])) {
                $found[$id] = Hrr::dot($this->phasorOf($this->facts[$id]), $phasor);
            }
        }
        return $found;
    }

    /** @var array<string, list<float>> the phasor of a fact by its keys: encoding is the slow part of a search here */
    private array $phasors = [];

    /** @return list<float> */
    private function phasorOf(Fact $fact): array {
        $key = implode("\x1f", $fact->keys);
        return $this->phasors[$key] ??= Hrr::phasor(Hrr::fact($fact->keys));
    }

    public function related(array $scopes, array $probes, float $threshold, int $limit): array {
        $scored = [];
        foreach ($this->facts as $fact) {
            if (!$fact->active || !in_array($fact->scope, $scopes, true) || $probes === []) {
                continue;
            }
            $phasor = $this->phasorOf($fact);
            $sim = max(array_map(static fn(array $probe): float => Hrr::dot($phasor, $probe), $probes));
            if ($sim >= $threshold) {
                $scored[] = [$sim, $fact];
            }
        }
        usort(
            $scored,
            /**
             * @param array{float, Fact} $a
             * @param array{float, Fact} $b
             */
            static fn(array $a, array $b): int => [$b[0], $b[1]->id] <=> [$a[0], $a[1]->id],
        );
        return array_slice(array_map(static fn(array $pair): Fact => $pair[1], $scored), 0, $limit);
    }

    public function active(int $limit): array {
        $found = array_filter($this->facts, static fn(Fact $f): bool => $f->active);
        ksort($found);
        return array_slice(array_values($found), 0, $limit);
    }

    public function recalledAny(array $factIds): array {
        $ever = array_map(static fn(array $r): int => $r[1], $this->recalls);
        return array_values(array_intersect($factIds, $ever));
    }

    public function encodeMissing(int $limit): int {
        return 0;
    }

    public function candidates(array $scopes, array $keys, int $limit, array $weights = []): array {
        $scored = [];
        foreach ($this->facts as $f) {
            $shared = $f->active && in_array($f->scope, $scopes, true) ? array_intersect($keys, $f->keys) : [];
            if ($shared !== []) {
                $scored[] = [array_sum(array_map(static fn(string $k): float => $weights[$k] ?? 1.0, array_unique($shared))), $f];
            }
        }
        usort(
            $scored,
            /**
             * @param array{float, Fact} $a
             * @param array{float, Fact} $b
             */
            static fn(array $a, array $b): int => [$b[0], $b[1]->id] <=> [$a[0], $a[1]->id],
        );
        return array_slice(array_map(static fn(array $pair): Fact => $pair[1], $scored), 0, $limit);
    }

    public function frequencies(array $scopes, array $keys): array {
        $active = array_filter($this->facts, static fn(Fact $f): bool => $f->active && in_array($f->scope, $scopes, true));
        $df = ['' => count($active)];
        foreach ($keys as $key) {
            $df[$key] = count(array_filter($active, static fn(Fact $f): bool => in_array($key, $f->keys, true)));
        }
        return $df;
    }

    public function recent(array $scopes, int $limit): array {
        $found = array_filter($this->facts, static fn(Fact $f): bool => $f->active && in_array($f->scope, $scopes, true));
        krsort($found);
        return array_slice(array_values($found), 0, $limit);
    }

    public function faded(array $scopes, float $below, int $limit): array {
        $facts = array_values(array_filter($this->facts, static fn(Fact $f): bool => $f->active && in_array($f->scope, $scopes, true) && $f->trust < $below));
        usort($facts, static fn(Fact $a, Fact $b): int => [$a->trust, $a->id] <=> [$b->trust, $b->id]);
        return array_slice($facts, 0, $limit);
    }

    public function rekey(\Closure $keys): int {
        $changed = 0;
        foreach ($this->facts as $id => $f) {
            $new = $keys($f->text);
            if (!$f->active || $new === [] || $new === $f->keys) {
                continue;
            }
            $this->facts[$id] = new Fact($id, $f->scope, $f->category, $f->text, $new, $f->trust, $f->provenance, $f->active, $f->version, $f->updatedAt);
            $changed++;
        }
        return $changed;
    }

    public function versions(int $factId): array {
        return $this->history[$factId] ?? [];
    }

    public function changeFact(int $id, string $text, array $keys, string $reason, ?string $source = null): ?Fact {
        $f = $this->facts[$id] ?? null;
        if ($f === null || !$f->active) {
            return null;
        }
        $this->history[$id][] = ['version' => $f->version + 1, 'text' => $text, 'reason' => $reason, 'source' => $source];
        return $this->facts[$id] = new Fact($id, $f->scope, $f->category, $text, $keys, $f->trust, $f->provenance, $f->active, $f->version + 1, time());
    }

    /** @var array<int, int> */
    public array $helpful = [];

    public function rateFact(int $id, float $delta, bool $helpful): ?float {
        $f = $this->facts[$id] ?? null;
        if ($f === null || !$f->active) {
            return null;
        }
        $trust = max(0.0, min(1.0, round($f->trust + $delta, 6)));
        $this->facts[$id] = new Fact($id, $f->scope, $f->category, $f->text, $f->keys, $trust, $f->provenance, true, $f->version, $f->updatedAt);
        $this->helpful[$id] = ($this->helpful[$id] ?? 0) + ($helpful ? 1 : 0);
        return $trust;
    }

    public function endFact(int $id, string $reason): bool {
        $f = $this->facts[$id] ?? null;
        if ($f === null || !$f->active) {
            return false;
        }
        $this->facts[$id] = new Fact($id, $f->scope, $f->category, $f->text, $f->keys, $f->trust, $f->provenance, false, $f->version, time());
        return true;
    }

    public function forgetFact(int $id, string $fingerprint): bool {
        if (!isset($this->facts[$id])) {
            return false;
        }
        $text = $this->facts[$id]->text;
        foreach ($this->proposals as $pid => $p) {
            if ($p->factId === $id || ($p->factId === null && $p->kind === Proposal::ADD && $p->text === $text)) {
                unset($this->proposals[$pid], $this->cards[$pid]);
            } elseif ($p->mergeWith === $id) {
                // as ON DELETE SET NULL: a merge whose second fact is gone stays, and cannot be applied
                $this->proposals[$pid] = $p->with(null, null, true);
            }
        }
        unset($this->facts[$id], $this->history[$id]);
        $this->tombstones[$fingerprint] = true;
        $this->recalls = array_values(array_filter($this->recalls, static fn(array $r): bool => $r[1] !== $id));
        return true;
    }

    public function forgotten(string $fingerprint): bool {
        return isset($this->tombstones[$fingerprint]);
    }

    public function propose(string $kind, ?int $factId, string $scope, string $text, string $reason, ?int $principalId, ?int $runId, bool $aboutPerson = false, ?string $provenance = null, array $keywords = [], ?int $mergeWith = null, ?int $factVersion = null, ?int $mergeVersion = null): Proposal {
        $id = $this->nextProposal++;
        return $this->proposals[$id] = new Proposal($id, $kind, $factId, $scope, $text, $reason, Proposal::PENDING, $principalId, $runId, $aboutPerson, time(), $provenance, $keywords, $mergeWith, $factVersion, $mergeVersion);
    }

    public function proposal(int $id): ?Proposal {
        return $this->proposals[$id] ?? null;
    }

    public function decide(int $id, string $status): ?Proposal {
        $p = $this->proposals[$id] ?? null;
        if ($p === null || $p->status !== Proposal::PENDING) {
            return null;
        }
        return $this->proposals[$id] = $p->with($status);
    }

    public function setCard(int $id, string $chatId, string $messageId): void {
        $this->cards[$id] = [$chatId, $messageId];
    }

    public function linkProposal(int $proposalId, int $factId): void {
        $p = $this->proposals[$proposalId] ?? null;
        if ($p !== null) {
            $this->proposals[$proposalId] = $p->with(null, $factId);
        }
    }

    public function cards(int $factId): array {
        $text = $this->facts[$factId]->text ?? null;
        $cards = [];
        foreach ($this->proposals as $pid => $p) {
            if (isset($this->cards[$pid]) && ($p->factId === $factId || $p->mergeWith === $factId || ($p->factId === null && $p->kind === Proposal::ADD && $p->text === $text))) {
                $cards[] = $this->cards[$pid];
            }
        }
        return $cards;
    }

    public function reopen(int $id): void {
        $p = $this->proposals[$id] ?? null;
        if ($p !== null && $p->status === Proposal::APPROVED) {
            $this->proposals[$id] = $p->with(Proposal::PENDING);
        }
    }

    public function pending(): array {
        return array_values(array_filter($this->proposals, static fn(Proposal $p): bool => $p->status === Proposal::PENDING));
    }

    public function core(string $section): string {
        return $this->coreTexts[$section] ?? '';
    }

    public function setCore(string $section, string $text): void {
        $this->coreTexts[$section] = $text;
    }

    public function markRecalled(int $conversationId, array $factIds, array $versions = []): void {
        foreach ($factIds as $id) {
            $this->recalls[] = [$conversationId, $id];
        }
    }

    /** Runs are not known here: the database ties a recall to its run. */
    public function receipt(int $runId): array {
        return [];
    }

    /** @var array<string, bool> "run:fact" => helpful; any recalled fact counts as given to any run */
    private array $marks = [];

    public function markHelpful(int $runId, int $factId, bool $helpful): bool {
        $key = $runId . ':' . $factId;
        if (isset($this->marks[$key]) || !in_array($factId, array_map(static fn(array $r): int => $r[1], $this->recalls), true)) {
            return false;
        }
        $this->marks[$key] = $helpful;
        return true;
    }

    public function usage(int $factId): array {
        $marks = array_filter($this->marks, static fn(string $key): bool => str_ends_with($key, ':' . $factId), ARRAY_FILTER_USE_KEY);
        return [
            'recalled' => count(array_filter($this->recalls, static fn(array $r): bool => $r[1] === $factId)),
            'helpful' => count(array_filter($marks)),
            'unhelpful' => count($marks) - count(array_filter($marks)),
        ];
    }

    public function recalled(int $conversationId, int $window): array {
        $mine = array_values(array_filter($this->recalls, static fn(array $r): bool => $r[0] === $conversationId));
        return array_values(array_unique(array_map(static fn(array $r): int => $r[1], array_slice($mine, -$window))));
    }
}

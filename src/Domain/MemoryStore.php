<?php

declare(strict_types=1);

namespace Mercurius\Memory\Domain;

/**
 * Storage of memory: facts with versions and tombstones, proposals to the owner, the always-in-context layer and the
 * recall log. One interface: these live and change together.
 *
 * spec: brief/memory.md#факты
 */
interface MemoryStore {
    /** @param list<string> $keys */
    public function addFact(string $scope, string $category, string $text, array $keys, float $trust, string $provenance, ?int $runId): Fact;

    public function fact(int $id): ?Fact;

    /**
     * HRR similarity of each listed fact to a query phasor, in [-1, 1]; facts without a vector are left out.
     *
     * spec: brief/memory.md#hrr-с-самого-начала — a fact's vector follows its keys on every write
     *
     * @param list<int> $factIds
     * @param list<float> $phasor
     * @return array<int, float>
     */
    public function similarities(array $factIds, array $phasor): array;

    /**
     * Active facts of the scopes whose similarity to any of the probes reaches the threshold, best first.
     *
     * @param list<string> $scopes
     * @param list<list<float>> $probes phasors
     * @return list<Fact>
     */
    public function related(array $scopes, array $probes, float $threshold, int $limit): array;

    /**
     * Active facts of every scope, oldest first: what the nightly check looks at.
     *
     * @return list<Fact>
     */
    public function active(int $limit): array;

    /**
     * Which of the facts were ever given to a run.
     *
     * @param list<int> $factIds
     * @return list<int>
     */
    public function recalledAny(array $factIds): array;

    /** Gives a vector to facts written before vectors existed; returns how many got one. */
    public function encodeMissing(int $limit): int;

    /**
     * Keys every active fact again where they differ from those of its text now (a new rule of the analyzer); not a
     * new version. Returns how many changed.
     *
     * spec: brief/memory.md#ключи-и-однокоренные
     *
     * @param \Closure(string): list<string> $keys
     */
    public function rekey(\Closure $keys): int;

    /**
     * Active facts of the scopes sharing at least one key: the greatest sum of the weights of the keys they hold first,
     * then the newest. Without weights every key weighs one.
     *
     * spec: brief/memory.md#оценка-и-отбор-фактов — candidates by what they match, not by age: an old fact of a frequent word is
     *       not pushed out of the window by newer ones
     *
     * @param list<string> $scopes
     * @param list<string> $keys
     * @param array<string, float> $weights the weight of a key, by key
     * @return list<Fact>
     */
    public function candidates(array $scopes, array $keys, int $limit, array $weights = []): array;

    /**
     * How many active facts of the scopes hold each key, and how many there are in all (key "").
     *
     * @param list<string> $scopes
     * @param list<string> $keys
     * @return array<string, int>
     */
    public function frequencies(array $scopes, array $keys): array;

    /**
     * @param list<string> $scopes
     * @return list<Fact> active facts, newest first
     */
    public function recent(array $scopes, int $limit): array;

    /**
     * Active facts whose trust fell under $below, weakest first: recall does not give them any more.
     *
     * @param list<string> $scopes
     * @return list<Fact>
     */
    public function faded(array $scopes, float $below, int $limit): array;

    /** @param list<string> $keys a new version; null when the fact is gone */
    public function changeFact(int $id, string $text, array $keys, string $reason, ?string $source = null): ?Fact;

    /**
     * The versions of a fact, oldest first: what it said, why it changed, who made the version.
     *
     * spec: brief/memory.md#факты
     *
     * @return list<array{version: int, text: string, reason: string, source: ?string}>
     */
    public function versions(int $factId): array;

    /**
     * The agent used the fact: trust moves by $delta within [0, 1], a useful one is counted; the time of the last change
     * stays. Null when there is no such active fact.
     */
    public function rateFact(int $id, float $delta, bool $helpful): ?float;

    /** The fact stops being true; it stays in the history. */
    public function endFact(int $id, string $reason): bool;

    /** The fact and its versions are deleted; only the fingerprint stays. */
    public function forgetFact(int $id, string $fingerprint): bool;

    public function forgotten(string $fingerprint): bool;

    /**
     * @param list<string> $keywords
     * @param ?int $mergeWith for a merge: the fact to forget; the versions are those of both facts the owner is shown
     */
    public function propose(string $kind, ?int $factId, string $scope, string $text, string $reason, ?int $principalId, ?int $runId, bool $aboutPerson = false, ?string $provenance = null, array $keywords = [], ?int $mergeWith = null, ?int $factVersion = null, ?int $mergeVersion = null): Proposal;

    public function proposal(int $id): ?Proposal;

    /** Moves a pending proposal to approved or rejected; null when it was decided already. */
    public function decide(int $id, string $status): ?Proposal;

    public function setCard(int $id, string $chatId, string $messageId): void;

    /** Ties an applied proposal to the fact it made, so forgetting the fact removes the proposal too. */
    public function linkProposal(int $proposalId, int $factId): void;

    /**
     * Cards of the proposals about a fact: tied to it, or adding its exact text.
     * @return list<array{string, string}> chat id, message id
     */
    public function cards(int $factId): array;

    /** An approved proposal whose change could not be applied goes back to pending. */
    public function reopen(int $id): void;

    /** @return list<Proposal> */
    public function pending(): array;

    /** The current text of profile or summary; '' when none. */
    public function core(string $section): string;

    public function setCore(string $section, string $text): void;

    /**
     * @param list<int> $factIds
     * @param array<int, int> $versions fact id => the version given; a fact not in it — version not kept
     */
    public function markRecalled(int $conversationId, array $factIds, array $versions = []): void;

    /**
     * The receipt of recall of a run: the facts it was given and their versions, in order.
     *
     * spec: brief/operations.md#шаги-и-хранение
     *
     * @return list<array{fact: int, version: ?int, helpful: ?bool}>
     */
    public function receipt(int $runId): array;

    /** @return list<int> facts given in the conversation among its last $window recalls */
    public function recalled(int $conversationId, int $window): array;

    /**
     * Marks whether a fact the run was given helped its answer; once per recall. False when the run was not given the
     * fact or the recall is marked already.
     *
     * spec: brief/memory.md#как-сделано-области-и-факты
     */
    public function markHelpful(int $runId, int $factId, bool $helpful): bool;

    /**
     * How a fact served: how many times it was given, and how many of those the agent marked as helping or not.
     *
     * @return array{recalled: int, helpful: int, unhelpful: int}
     */
    public function usage(int $factId): array;
}

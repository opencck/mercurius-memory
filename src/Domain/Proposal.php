<?php

declare(strict_types=1);

namespace Mercurius\Memory\Domain;

/**
 * A change to memory the agent asks the owner to approve.
 *
 * spec: brief/memory.md#как-сделано-области-и-факты — new facts and edits of layer 1 go through the owner
 */
final class Proposal {
    public const ADD = 'add';
    public const CHANGE = 'change';
    public const END = 'end';
    public const FORGET = 'forget';
    public const PROFILE = 'profile';
    public const SUMMARY = 'summary';
    public const KINDS = [self::ADD, self::CHANGE, self::END, self::FORGET, self::PROFILE, self::SUMMARY];
    /** two facts into one: proposed by sleep only, not among the kinds the agent may ask for */
    public const MERGE = 'merge';
    /** the provenance of a proposal sleep made, and the source of a version sleep wrote */
    public const SLEEP = 'sleep';

    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    public function __construct(
        public readonly int $id,
        public readonly string $kind,
        public readonly ?int $factId,
        public readonly string $scope,
        public readonly string $text,
        public readonly string $reason,
        public readonly string $status,
        public readonly ?int $principalId,
        public readonly ?int $runId,
        /** the fact is about another person: it goes only to the owner's memory, the card says so */
        public readonly bool $aboutPerson = false,
        /** when it was proposed, unix seconds; null — not known */
        public readonly ?int $createdAt = null,
        /** where the fact came from (Fact::OWNER_SAID, AGENT, TOOL); null — not said */
        public readonly ?string $provenance = null,
        /** @var list<string> keywords the agent gave with the fact */
        public readonly array $keywords = [],
        /** for a merge: the fact forgotten once its text is in the kept one (factId) */
        public readonly ?int $mergeWith = null,
        /** for a merge: the versions of the kept and the forgotten fact the owner was shown */
        public readonly ?int $factVersion = null,
        public readonly ?int $mergeVersion = null,
    ) {}

    /** The same proposal with another status or fact. */
    public function with(?string $status = null, ?int $factId = null, bool $clearMerge = false): self {
        return new self(
            $this->id, $this->kind, $factId ?? $this->factId, $this->scope, $this->text, $this->reason, $status ?? $this->status,
            $this->principalId, $this->runId, $this->aboutPerson, $this->createdAt, $this->provenance, $this->keywords,
            $clearMerge ? null : $this->mergeWith, $this->factVersion, $this->mergeVersion,
        );
    }
}

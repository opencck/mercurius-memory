<?php

declare(strict_types=1);

namespace Mercurius\Memory\Domain;

/**
 * A change to memory the agent asks the owner to approve.
 *
 * spec: BRIEF.md#фаза-6-по-частям — new facts and edits of layer 1 go through the owner
 */
final class Proposal {
    public const ADD = 'add';
    public const CHANGE = 'change';
    public const END = 'end';
    public const FORGET = 'forget';
    public const PROFILE = 'profile';
    public const SUMMARY = 'summary';
    public const KINDS = [self::ADD, self::CHANGE, self::END, self::FORGET, self::PROFILE, self::SUMMARY];

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
    ) {}
}

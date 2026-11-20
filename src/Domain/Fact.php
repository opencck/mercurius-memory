<?php

declare(strict_types=1);

namespace Mercurius\Memory\Domain;

/**
 * One atomic statement the agent remembers, in its current version.
 *
 * spec: BRIEF.md#факты
 */
final class Fact {
    public const KNOWLEDGE = 'knowledge';

    public const OWNER_SAID = 'owner_said';
    public const OWNER_CONFIRMED = 'owner_confirmed';
    public const AGENT = 'agent';
    /** read from a tool result: a page, a file, a command */
    public const TOOL = 'tool';

    /** @param list<string> $keys */
    public function __construct(
        public readonly int $id,
        /** owner | principal:<id> | chat:<id> */
        public readonly string $scope,
        public readonly string $category,
        public readonly string $text,
        public readonly array $keys,
        public readonly float $trust,
        public readonly string $provenance,
        public readonly bool $active,
        public readonly int $version,
        /** unix seconds of the last change */
        public readonly int $updatedAt,
    ) {}
}

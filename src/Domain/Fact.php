<?php

declare(strict_types=1);

namespace Mercurius\Memory\Domain;

/**
 * One atomic statement the agent remembers, in its current version.
 *
 * spec: brief/memory.md#факты
 */
final class Fact {
    public const KNOWLEDGE = 'knowledge';

    public const OWNER_SAID = 'owner_said';
    public const OWNER_CONFIRMED = 'owner_confirmed';
    public const AGENT = 'agent';
    /** read from a tool result: a page, a file, a command */
    public const TOOL = 'tool';

    /** sleep has not looked at the fact yet */
    public const BARE = 0;
    /** sleep added words in the fact's language */
    public const WORDS = 1;
    /** sleep added words and their translations into the other languages of memory */
    public const TRANSLATED = 2;

    /**
     * @param list<string> $keys
     * @param list<string> $words
     */
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
        /** words it is found by besides its text: the agent's keywords and the words sleep added */
        public readonly array $words = [],
        /** how far sleep enriched it: BARE, WORDS or TRANSLATED */
        public readonly int $enriched = self::BARE,
    ) {}

    /**
     * The same fact with some fields changed.
     *
     * @param ?list<string> $keys
     * @param ?list<string> $words
     */
    public function with(?string $text = null, ?array $keys = null, ?float $trust = null, ?bool $active = null, ?int $version = null, ?int $updatedAt = null, ?array $words = null, ?int $enriched = null): self {
        return new self(
            $this->id, $this->scope, $this->category, $text ?? $this->text, $keys ?? $this->keys, $trust ?? $this->trust,
            $this->provenance, $active ?? $this->active, $version ?? $this->version, $updatedAt ?? $this->updatedAt,
            $words ?? $this->words, $enriched ?? $this->enriched,
        );
    }
}

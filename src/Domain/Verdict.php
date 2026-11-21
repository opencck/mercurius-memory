<?php

declare(strict_types=1);

namespace Mercurius\Memory\Domain;

/**
 * What the model of sleep said of a pair of facts: one thing, one adds a detail to the other, they contradict, the newer
 * replaces the older, or they are unrelated — with how sure it is, and for a replacement which of the two is newer.
 *
 * spec: brief/memory.md#судья-пары
 */
final class Verdict {
    public const SAME = 'same';
    public const ADDS_DETAIL = 'adds_detail';
    public const CONTRADICTS = 'contradicts';
    public const SUPERSEDES = 'supersedes';
    public const UNRELATED = 'unrelated';
    public const LABELS = [self::SAME, self::ADDS_DETAIL, self::CONTRADICTS, self::SUPERSEDES, self::UNRELATED];

    /** @param 'a'|'b'|null $newer for a replacement: the fact that holds now */
    public function __construct(
        public readonly string $label,
        public readonly float $confidence,
        public readonly ?string $newer = null,
    ) {}

    public static function of(string $label, float $confidence, ?string $newer = null): ?self {
        if (!in_array($label, self::LABELS, true) || $confidence < 0.0 || $confidence > 1.0) {
            return null;
        }
        return new self($label, $confidence, in_array($newer, ['a', 'b'], true) ? $newer : null);
    }
}

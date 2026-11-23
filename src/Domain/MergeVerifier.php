<?php

declare(strict_types=1);

namespace Mercurius\Memory\Domain;

/**
 * Another model than the one that wrote a merged text reads both facts and the text: is every statement of both kept.
 *
 * spec: brief/memory.md#слияние
 */
interface MergeVerifier {
    /**
     * @param list<array{Fact, Fact, string}> $merges the fact that stays, the other one and the merged text
     * @return list<?bool> by the order of the merges: true — everything kept; false — something lost or changed;
     *         null — nobody else could check it
     */
    public function verify(array $merges): array;

    /** @return array{int, int, float} calls, tokens and dollars spent since the last time this was asked */
    public function spent(): array;
}

<?php

declare(strict_types=1);

namespace Mercurius\Memory\App;

use Mercurius\Memory\Domain\Fact;
use Mercurius\Memory\Domain\TextAnalyzer;

/**
 * The pairs of facts the code cannot settle and the model of sleep is asked about: within one scope, not an exact copy
 * (that the code settles itself), but sharing a fifth of their keys, or naming the same thing (an identifier), or taken
 * for a conflict by the code's rule (to be confirmed). The strongest first, within a limit: the model sees only these
 * pairs, never the whole memory. Measured on the pair sets: closeness by HRR added no pair the words missed, only noise.
 *
 * spec: brief/memory.md#серая-зона
 */
final class GrayZone {
    /** the share of shared keys from which a pair is a candidate: measured, 0.25 loses a tenth of the pairs, 0.15 doubles the noise */
    public const JACCARD = 0.2;
    public const LIMIT = 40;

    /**
     * @param list<Fact> $facts
     * @return list<array{Fact, Fact, string}> the pairs, with why each was taken: words, name, conflict
     */
    public function pairs(array $facts, int $limit = self::LIMIT): array {
        $byScope = [];
        foreach ($facts as $fact) {
            $byScope[$fact->scope][] = $fact;
        }
        $found = [];
        $checked = 0;
        foreach ($byScope as $group) {
            foreach ($group as $i => $a) {
                foreach (array_slice($group, $i + 1) as $b) {
                    if (++$checked > Consolidation::PAIR_BUDGET) {
                        break 3;
                    }
                    if (Consolidation::duplicates($a, $b)) {
                        continue;
                    }
                    $words = self::jaccard($a, $b);
                    $named = self::sharedName($a, $b);
                    $conflict = Consolidation::conflict($a, $b);
                    $why = match (true) {
                        $conflict => 'conflict',
                        $named => 'name',
                        $words >= self::JACCARD => 'words',
                        default => null,
                    };
                    if ($why !== null) {
                        $found[] = [max($words / self::JACCARD, $conflict || $named ? 2.0 : 0.0), $a, $b, $why];
                    }
                }
            }
        }
        usort($found, static fn(array $x, array $y): int => $y[0] <=> $x[0]);
        return array_map(static fn(array $p): array => [$p[1], $p[2], $p[3]], array_slice($found, 0, $limit));
    }

    public static function jaccard(Fact $a, Fact $b): float {
        $all = count(array_unique([...$a->keys, ...$b->keys]));
        return $all === 0 ? 0.0 : (float) count(array_intersect($a->keys, $b->keys)) / (float) $all;
    }

    /** Both name the same host, version, path or address. */
    private static function sharedName(Fact $a, Fact $b): bool {
        foreach (array_intersect($a->keys, $b->keys) as $key) {
            if (TextAnalyzer::isIdentifier($key)) {
                return true;
            }
        }
        return false;
    }
}

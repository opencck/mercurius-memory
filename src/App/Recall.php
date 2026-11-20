<?php

declare(strict_types=1);

namespace Mercurius\Memory\App;

use Closure;
use Mercurius\Memory\Domain\Fact;
use Mercurius\Memory\Domain\Hrr;
use Mercurius\Memory\Domain\MemoryStore;
use Mercurius\Memory\Domain\TextAnalyzer;

/**
 * Facts that answer a message, not merely share a word with it.
 *
 * spec: brief/memory.md#анализ-текста — coverage of the message's informative keys weighted by IDF within the readable
 *       scopes, times trust and recency; a budget in characters; a fact already given in this conversation is not
 *       repeated within the window
 * ref: references/hermes-plugin-holographic/retrieval.py@4a794f8:L964-L1060 (relevant) — IDF weights, words no fact
 *      holds count against coverage at the smallest weight, a matched identifier is decisive, one shared word that
 *      other facts hold too does not pick a fact out; without n-grams, name forms and the FTS index: keys are stems
 * ref: references/hermes-plugin-holographic/retrieval.py@4a794f8:L615-L636 (_rerank) — HRR as a share of the score of
 *      candidates the words already picked, shifted to [0, 1]; it never lets a fact past the gate
 */
final class Recall {
    public const BUDGET = 1500;
    public const MIN_COVERAGE = 0.34;
    /** A match through a same-root word counts this much of an exact one. */
    public const ROOT_WEIGHT = 0.6;
    public const WINDOW = 20;
    private const CANDIDATES = 100;
    private const HALF_LIFE_DAYS = 180.0;
    /** share of HRR in the score of a fact that passed the gate */
    public const HRR_WEIGHT = 0.3;
    public const RELATED_LIMIT = 10;
    /**
     * facts of a name the message mentions, found without shared words
     *
     * ref: references/hermes-plugin-holographic/__init__.py@4a794f8:L433 (_ENTITY_FACTS), L1406-L1415 — stream 3 of
     *      prefetch: the top facts of the entities the message names, no word overlap needed, with the same de-dup and
     *      repeat window; holographic keeps an index of entities, here a capitalized word inside a sentence (or an
     *      identifier) is probed by HRR
     */
    public const ENTITY_FACTS = 2;
    /**
     * A fact under this trust is not recalled: two «not useful» marks sink a fact the agent inferred.
     * ref: references/hermes-plugin-holographic/__init__.py@4a794f8:L761 — min_trust_threshold 0.3
     */
    public const MIN_TRUST = 0.3;

    /** @var Closure(): int */
    private readonly Closure $now;

    /** @param (Closure(): int)|null $now unix seconds */
    public function __construct(
        private readonly MemoryStore $store,
        private readonly TextAnalyzer $analyzer,
        ?Closure $now = null,
        private readonly float $hrrWeight = self::HRR_WEIGHT,
    ) {
        $this->now = $now ?? static fn(): int => time();
    }

    /**
     * @param list<string> $scopes
     * @return list<Fact> best first, within the budget; marked as given when a conversation is named
     */
    public function find(string $message, array $scopes, ?int $conversationId = null, int $budget = self::BUDGET): array {
        if ($scopes === []) {
            return [];
        }
        $given = $conversationId === null ? [] : array_flip($this->store->recalled($conversationId, self::WINDOW));
        $found = $this->byWords($message, $scopes, $given, $budget);
        // spec: brief/memory.md#факты — and up to two facts of a name the message mentions, without shared words
        $left = $budget - array_sum(array_map(static fn(Fact $f): int => mb_strlen(self::line($f)) + 1, $found));
        $found = [...$found, ...$this->named($message, $scopes, $given, $found, $left)];
        if ($conversationId !== null && $found !== []) {
            // spec: brief/operations.md#память-в-сводке-и-квитанция-извлечения — the version given goes into the receipt of recall
            $versions = [];
            foreach ($found as $fact) {
                $versions[$fact->id] = $fact->version;
            }
            $this->store->markRecalled($conversationId, array_map(static fn(Fact $f): int => $f->id, $found), $versions);
        }
        return $found;
    }

    /**
     * The facts a name of the message points to: a capitalized word inside a sentence or an identifier, probed by HRR;
     * at most ENTITY_FACTS, most trusted first, not given already.
     *
     * @param list<string> $scopes
     * @param array<int, int> $given
     * @param list<Fact> $have
     * @return list<Fact>
     */
    private function named(string $message, array $scopes, array $given, array $have, int $left): array {
        $names = self::names($message);
        if ($names === []) {
            return [];
        }
        $taken = [];
        foreach ($have as $fact) {
            $taken[$fact->id] = true;
        }
        $candidates = [];
        foreach ($names as $name) {
            foreach ($this->related($name, $scopes, self::ENTITY_FACTS * 3) as $fact) {
                if (!isset($taken[$fact->id]) && !isset($given[$fact->id])) {
                    $candidates[$fact->id] = $fact;
                }
            }
        }
        usort($candidates, static fn(Fact $a, Fact $b): int => [$b->trust, $b->id] <=> [$a->trust, $a->id]);
        $found = [];
        foreach ($candidates as $fact) {
            $length = mb_strlen(self::line($fact)) + 1;
            if (count($found) >= self::ENTITY_FACTS || $length > $left) {
                break;
            }
            $left -= $length;
            $found[] = $fact;
        }
        return $found;
    }

    /**
     * Names in a message: a word with a capital letter that does not open a sentence, an identifier (letters with
     * digits or a dash, like pve-01), a word in quotes.
     *
     * @return list<string>
     */
    public static function names(string $message): array {
        $names = [];
        foreach (preg_split('/(?<=[.!?…])\s+|\n+/u', $message) ?: [] as $sentence) {
            $words = preg_split('/[^\p{L}\p{N}-]+/u', trim($sentence), -1, PREG_SPLIT_NO_EMPTY);
            $words = $words === false ? [] : $words;
            foreach ($words as $i => $word) {
                $capital = $i > 0 && mb_strlen($word) > 1 && mb_strtoupper(mb_substr($word, 0, 1)) === mb_substr($word, 0, 1) && preg_match('/^\p{Lu}/u', $word) === 1;
                if ($capital || TextAnalyzer::isIdentifier(mb_strtolower($word))) {
                    $names[] = $word;
                }
            }
        }
        if (preg_match_all('/[«"„]([^»"“]{2,40})[»"“]/u', $message, $quoted) > 0) {
            $names = [...$names, ...$quoted[1]];
        }
        return array_values(array_unique($names));
    }

    /**
     * The facts the words of a message cover well enough, best first, within the budget.
     *
     * @param list<string> $scopes
     * @param array<int, int> $given facts given in the conversation lately
     * @return list<Fact>
     */
    private function byWords(string $message, array $scopes, array $given, int $budget): array {
        $keys = $this->analyzer->keys($message);
        if ($keys === []) {
            return [];
        }
        // spec: brief/memory.md#анализ-текста — a same-root word counts ROOT_WEIGHT of an exact one and never recalls alone
        // ref: references/hermes-plugin-holographic/retrieval.py@4a794f8:L276-L291 — the root match weighed at
        //      _DERIV_WEIGHT; here the question's stems are widened, so facts keep their keys as they are
        $roots = [];
        foreach ($keys as $key) {
            foreach ($this->analyzer->related($key) as $root) {
                if (!in_array($root, $keys, true)) {
                    $roots[$root] = true;
                }
            }
        }
        $roots = array_map(strval(...), array_keys($roots));
        $df = $this->store->frequencies($scopes, [...$keys, ...$roots]);
        $total = (float) max($df[''], 1);
        $weights = [];
        foreach ($keys as $key) {
            if (($df[$key] ?? 0) > 0) {
                $weights[$key] = log(1.0 + $total / (float) $df[$key]);
            }
        }
        $rootWeights = [];
        foreach ($roots as $root) {
            if (($df[$root] ?? 0) > 0) {
                $rootWeights[$root] = self::ROOT_WEIGHT * log(1.0 + $total / (float) $df[$root]);
            }
        }
        if ($weights === []) {
            return [];
        }
        $unknown = count($keys) - count($weights);
        $sum = array_sum($weights) + (float) $unknown * min($weights);
        $now = ($this->now)();

        // array keys of numeric words come back as ints
        $known = array_map(strval(...), array_keys($weights));
        $knownRoots = array_map(strval(...), array_keys($rootWeights));
        /** @var list<array{Fact, float, float}> $passed fact, its coverage score, its age factor */
        $passed = [];
        // spec: brief/memory.md#анализ-текста — the candidates are the facts that match the most, weighed as the coverage is
        foreach ($this->store->candidates($scopes, [...$known, ...$knownRoots], self::CANDIDATES, $weights + $rootWeights) as $fact) {
            if (isset($given[$fact->id]) || $fact->trust < self::MIN_TRUST - 1e-9) {
                continue;
            }
            $matched = array_values(array_intersect($known, $fact->keys));
            if ($matched === []) {
                continue;
            }
            $identifier = false;
            foreach ($matched as $key) {
                $identifier = $identifier || TextAnalyzer::isIdentifier($key);
            }
            $rooted = array_values(array_intersect($knownRoots, $fact->keys));
            $coverage = min(1.0, (array_sum(array_map(static fn(string $k): float => $weights[$k], $matched))
                + array_sum(array_map(static fn(string $k): float => $rootWeights[$k], $rooted))) / $sum);
            // One shared word of a longer message, held by other facts too ("what day is it" and "every day"), is
            // about none of them.
            $lone = count($keys) > 1 && count($matched) + count($rooted) === 1 && ($df[$matched[0]] ?? 0) > 1;
            if (!$identifier && ($coverage < self::MIN_COVERAGE || $lone)) {
                continue;
            }
            $age = (float) max(0, $now - $fact->updatedAt) / 86400.0;
            $passed[] = [$fact, $identifier ? max($coverage, 1.0) : $coverage, $fact->trust * 0.5 ** ($age / self::HALF_LIFE_DAYS)];
        }
        $hrr = [];
        if ($this->hrrWeight > 0.0 && count($passed) > 1) {
            $hrr = $this->store->similarities(array_map(static fn(array $p): int => $p[0]->id, $passed), Hrr::phasor(Hrr::query($keys)));
        }
        /** @var list<array{Fact, float}> $scored */
        $scored = [];
        foreach ($passed as [$fact, $coverage, $factor]) {
            $relevance = isset($hrr[$fact->id]) ? (1.0 - $this->hrrWeight) * $coverage + $this->hrrWeight * ($hrr[$fact->id] + 1.0) / 2.0 : $coverage;
            $scored[] = [$fact, $relevance * $factor];
        }
        usort($scored, self::better(...));

        $found = [];
        $left = $budget;
        foreach ($scored as [$fact]) {
            $length = mb_strlen(self::line($fact)) + 1;
            if ($length > $left) {
                continue;
            }
            $left -= $length;
            $found[] = $fact;
        }
        return $found;
    }

    /**
     * Facts that fell under the trust threshold, weakest first: recall does not give them, the owner decides on them.
     *
     * spec: brief/memory.md#факты — the weekly review shows facts that fell under the threshold
     *
     * @param list<string> $scopes
     * @return list<Fact>
     */
    public function faded(array $scopes, int $limit = 20): array {
        return $this->store->faded($scopes, self::MIN_TRUST - 1e-9, $limit);
    }

    /**
     * Facts tied to a word or a name by HRR: as an entity or as a word of the content.
     *
     * ref: references/hermes-plugin-holographic/retrieval.py@4a794f8:L819-L839 (related) — the best of the entity and
     *      content probes against the noise threshold; one database query instead of a pass over every vector
     *
     * @param list<string> $scopes
     * @return list<Fact>
     */
    public function related(string $word, array $scopes, int $limit = self::RELATED_LIMIT): array {
        $keys = $this->analyzer->keys($word);
        if ($keys === [] || $scopes === []) {
            return [];
        }
        $probes = [];
        foreach ($keys as $key) {
            foreach (Hrr::probes($key) as $probe) {
                $probes[] = Hrr::phasor($probe);
            }
        }
        return array_values(array_filter(
            $this->store->related($scopes, $probes, Hrr::RELATED_THRESHOLD, $limit),
            static fn(Fact $f): bool => $f->trust >= self::MIN_TRUST - 1e-9,
        ));
    }

    /**
     * @param array{Fact, float} $a
     * @param array{Fact, float} $b
     */
    private static function better(array $a, array $b): int {
        return $b[1] <=> $a[1] ?: $b[0]->id <=> $a[0]->id;
    }

    /** @param list<Fact> $facts one line per fact, whole */
    public static function format(array $facts): string {
        return implode("\n", array_map(self::line(...), $facts));
    }

    private static function line(Fact $fact): string {
        return sprintf('[#%d] %s', $fact->id, $fact->text);
    }
}

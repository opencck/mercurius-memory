<?php

declare(strict_types=1);

namespace Mercurius\Memory\App;

use Closure;
use Mercurius\Memory\Domain\ContentScreen;
use Mercurius\Memory\Domain\Fact;
use Mercurius\Memory\Domain\MemoryStore;
use Mercurius\Memory\Domain\SleepEnricher;
use Mercurius\Memory\Domain\TextAnalyzer;

use function Mercurius\Memory\t;

/**
 * Nightly words for facts: the model of sleep gives words a fact may be asked about by, the code keeps those that add
 * a key, hold no secret and no number or identifier the fact does not have, and — while translations are off — are in
 * the fact's own script. The text of a fact never changes here; the words live beside it and are keyed again with it.
 * Pairs of one word in two spellings join the table of words that mean one thing.
 *
 * spec: brief/memory.md#ночное-обогащение
 * ref: references/hermes-plugin-holographic/__init__.py@4a794f8:L2662-L2678 (_update, _enrich) — keywords are a search
 *      aid and not a change of the fact (its age stays); facts without them get 3-8 synonyms, other roots and
 *      translations. Here the model of sleep gives them and the code checks them; facts go oldest first, not most
 *      recalled first, and translations wait for the owner's setting
 */
final class Enrichment {
    /** facts a night */
    public const FACTS = 20;
    /** words kept for a fact a night */
    public const WORDS = 8;
    /** a word of more than this many parts is a phrase, not a word to be asked by */
    private const PARTS = 3;
    private const WORD_MAX = 40;
    /** groups of spellings added a night */
    public const SPELLINGS = 3;

    /**
     * @param Closure(): bool $translate whether translations are on now
     */
    public function __construct(
        private readonly MemoryStore $store,
        private readonly TextAnalyzer $analyzer,
        private readonly ContentScreen $screen,
        private readonly SleepEnricher $enricher,
        private readonly Closure $translate,
        private readonly ?Aliases $aliases = null,
    ) {}

    /**
     * @param array<int, true> $busy facts a pending card asks about: they are left for another night
     * @param (Closure(string): bool)|null $may whether the model may see facts of a scope (a project whose sleep is off may not)
     * @param list<int> $first facts to take before the oldest: those search does not find by their questions
     * @return array{int, int, list<string>} facts enriched, words added, lines about spellings added to the table
     */
    public function run(array $busy = [], ?Closure $may = null, array $first = []): array {
        $translate = ($this->translate)();
        $wanted = $translate ? Fact::TRANSLATED : Fact::WORDS;
        $ahead = [];
        foreach ($first as $id) {
            $fact = $this->store->fact($id);
            if ($fact !== null && $fact->active && $fact->enriched < $wanted) {
                $ahead[$id] = $fact;
            }
        }
        $facts = array_values(array_filter(
            [...array_values($ahead), ...array_filter($this->store->unenriched($wanted, self::FACTS * 2), static fn(Fact $f): bool => !isset($ahead[$f->id]))],
            static fn(Fact $f): bool => !isset($busy[$f->id]) && ($may === null || $may($f->scope)),
        ));
        $facts = array_slice($facts, 0, self::FACTS);
        if ($facts === []) {
            return [0, 0, []];
        }
        $answers = $this->enricher->enrich($facts, $translate);
        [$enriched, $added, $lines, $spellings] = [0, 0, [], 0];
        foreach ($facts as $i => $fact) {
            $answer = $answers[$i] ?? null;
            if ($answer === null) {
                continue;
            }
            $words = $this->words($fact, $answer[0], $translate);
            $all = array_values(array_unique([...$fact->words, ...$words]));
            $keys = $fact->keys;
            foreach ($words as $word) {
                $keys = [...$keys, ...$this->analyzer->keys($word)];
            }
            if ($this->store->enrich($fact->id, $fact->version, $all, array_values(array_unique($keys)), $wanted)) {
                $enriched++;
                $added += count($words);
            }
            foreach ($answer[1] as $pair) {
                if ($spellings >= self::SPELLINGS || $this->aliases === null) {
                    break;
                }
                $line = $this->spelling($fact, $pair);
                if ($line !== null) {
                    $spellings++;
                    $lines[] = $line;
                }
            }
        }
        return [$enriched, $added, $lines];
    }

    /** @return array{int, int, float} */
    public function spent(): array {
        return $this->enricher->spent();
    }

    /**
     * The words of the model's answer that may be kept for the fact.
     *
     * @param list<string> $words
     * @return list<string>
     */
    public function words(Fact $fact, array $words, bool $translate): array {
        $marks = array_fill_keys(MergeGuard::marks($fact->text), true);
        $script = MergeGuard::script($fact->text);
        $have = array_fill_keys($fact->keys, true);
        $kept = [];
        foreach ($words as $word) {
            $word = mb_strtolower(trim($word, " \t\n\r\0\x0B.,;:!?\"'«»()[]"));
            if (mb_strlen($word) < 2 || mb_strlen($word) > self::WORD_MAX || count(preg_split('~\s+~u', $word) ?: []) > self::PARTS) {
                continue;
            }
            if (array_diff_key(array_fill_keys(MergeGuard::marks($word), true), $marks) !== []) {
                continue;
            }
            if ($this->screen->problem($word, self::WORD_MAX) !== null) {
                continue;
            }
            if (!$translate && MergeGuard::script($word) !== $script) {
                continue;
            }
            $keys = $this->analyzer->keys($word);
            if ($keys === [] || array_diff_key(array_fill_keys($keys, true), $have) === []) {
                continue;
            }
            foreach ($keys as $key) {
                $have[$key] = true;
            }
            $kept[] = $word;
            if (count($kept) >= self::WORDS) {
                break;
            }
        }
        return $kept;
    }

    /**
     * A pair of spellings joins the table when one of them is a word of the fact's text and the other is not.
     *
     * @param list<string> $pair
     */
    private function spelling(Fact $fact, array $pair): ?string {
        if (count($pair) !== 2 || $this->aliases === null) {
            return null;
        }
        $text = ' ' . mb_strtolower(preg_replace('~[^\p{L}\p{N}]+~u', ' ', $fact->text) ?? '') . ' ';
        [$a, $b] = [mb_strtolower(trim($pair[0])), mb_strtolower(trim($pair[1]))];
        $inA = str_contains($text, ' ' . $a . ' ');
        $inB = str_contains($text, ' ' . $b . ' ');
        if ($a === $b || $inA === $inB) {
            return null;
        }
        [$changed, $said] = $this->aliases->add([$a, $b], null);
        return $changed ? sprintf(t('app.consolidation.spelling_added'), $said) : null;
    }
}

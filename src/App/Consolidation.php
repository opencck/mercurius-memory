<?php

declare(strict_types=1);

namespace Mercurius\Memory\App;

use Closure;
use InvalidArgumentException;
use Mercurius\Memory\Domain\ConsolidationJournal;
use Mercurius\Memory\Domain\Fact;
use Mercurius\Memory\Domain\MemoryStore;
use Mercurius\Memory\Domain\Proposal;
use Mercurius\Memory\Domain\SleepJudge;
use Mercurius\Memory\Domain\SleepMerger;
use Mercurius\Memory\Domain\TextAnalyzer;
use Mercurius\Memory\Domain\Verdict;

use function Mercurius\Memory\t;

/**
 * The nightly check of memory, by code: duplicates become a proposal to forget one of them, facts that disagree in
 * numbers and facts nobody used are listed for the owner. Nothing is changed without the owner; a night with no changes
 * in memory does nothing. The guard against a loss of memory is apart (LossGuard): it runs even when sleep does not.
 *
 * spec: brief/memory.md#консолидация-dreaming
 * ref: references/hermes-dreaming/skills/dreaming/scripts/dream.py@21878d3:L3035-L3068 (find_conflicts) — shared words
 *      and differing numbers make a conflict, not a duplicate; L5184-L5190 (_decay_candidates) — unused, old, low trust,
 *      a question and not a deletion; one duplicate pair a night, paused 180 days (scoring.md:L554-L570)
 */
final class Consolidation {
    public const FACTS = 2000;
    public const PAIR_BUDGET = 200_000;
    public const DUPLICATE_JACCARD = 0.8;
    public const CONFLICT_SHARED = 3;
    public const CONFLICT_OVERLAP = 0.45;
    public const UNUSED_DAYS = 28;
    public const UNUSED_TRUST = 0.5;
    public const PAUSE_DUPLICATE = 180;
    public const PAUSE_NOTICE = 14;
    public const LIST_CAP = 5;
    private const LINE = 160;

    /** a verdict under this is not used */
    public const MIN_CONFIDENCE = 0.6;
    /** cards a night: the code's copy, the model's copies and merges together */
    public const CARDS = 3;

    /** @var Closure(): int */
    private readonly Closure $now;
    private ?SleepJudge $judge = null;
    private ?SleepMerger $merger = null;
    /** @var list<array{Fact, Fact}> pairs the model found to add a detail to each other: candidates to merge */
    private array $merges = [];
    private static ?TextAnalyzer $analyzer = null;

    /**
     * @param Closure(Proposal): bool $show sends a proposal's card to the owner
     * @param Closure(string): bool $tell writes to the owner; false when the message was not delivered
     * @param (Closure(): int)|null $now unix seconds
     */
    public function __construct(
        private readonly MemoryStore $store,
        private readonly Proposals $proposals,
        private readonly ConsolidationJournal $journal,
        private readonly Closure $show,
        private readonly Closure $tell,
        ?Closure $now = null,
    ) {
        $this->now = $now ?? static fn(): int => time();
    }

    /**
     * The model of sleep, asked about the gray zone: pairs the code cannot settle.
     *
     * spec: brief/memory.md#судья-пары
     */
    public function useJudge(SleepJudge $judge): void {
        $this->judge = $judge;
    }

    /**
     * The model of sleep, asked to write one text for a pair where one fact adds a detail to the other.
     *
     * spec: brief/memory.md#слияние
     */
    public function useMerger(SleepMerger $merger): void {
        $this->merger = $merger;
    }

    /** @return list<array{Fact, Fact}> the pairs of the last pass the model found to add a detail to each other */
    public function merges(): array {
        return $this->merges;
    }

    /**
     * @param bool $force check even when memory has not changed since the last pass
     * @return string what was done, for the log or the owner's command
     */
    public function run(bool $force = false): string {
        $started = hrtime(true);
        $facts = $this->store->active(self::FACTS);
        $count = count($facts);
        $chars = array_sum(array_map(static fn(Fact $f): int => mb_strlen($f->text), $facts));
        $last = $this->journal->last();
        $changed = $last === null || $last['facts'] !== $count || $last['chars'] !== $chars
            || array_filter($facts, static fn(Fact $f): bool => $f->updatedAt >= $last['at']) !== [];
        if (!$force && !$changed) {
            $this->journal->save(ConsolidationJournal::SKIPPED, $count, $chars, self::ms($started), []);
            return t('app.consolidation.pamyat_ne_menyalas_proveryat_nechego');
        }

        $report = [];
        /** @var list<array{string, int, int}> $pending findings that count as shown once the report is delivered */
        $pending = [];
        $duplicate = $this->duplicate($facts);
        if ($duplicate !== null) {
            $report[] = $duplicate;
        }
        // spec: brief/memory.md#судья-пары — the model's word on the gray zone; a conflict of the code it ruled out is not shown
        $cards = $duplicate === null ? self::CARDS : self::CARDS - 1;
        [$judged, $settled] = $this->judged($facts, $cards, $pending);
        array_push($report, ...$this->conflicts($facts, $pending, $settled), ...$judged, ...$this->merged($cards), ...$this->unused($facts, $pending));

        // spec: brief/memory.md#судья-пары — what the model of sleep cost goes into the journal and the report
        [$calls, $tokens, $cost] = $this->judge?->spent() ?? [0, 0, 0.0];
        if ($this->merger !== null && $this->merger !== $this->judge) {
            [$c, $t, $d] = $this->merger->spent();
            [$calls, $tokens, $cost] = [$calls + $c, $tokens + $t, $cost + $d];
        }
        if ($report === []) {
            $this->journal->save(ConsolidationJournal::DONE, $count, $chars, self::ms($started), $report, $calls);
            return sprintf(t('app.consolidation.provereno_faktov_zamechaniy_net'), $count) . ($calls > 0 ? ' ' . sprintf(t('app.consolidation.model_spent'), $calls, $tokens, $cost) : '');
        }
        if ($calls > 0) {
            $report[] = sprintf(t('app.consolidation.model_spent'), $calls, $tokens, $cost);
        }
        $text = t('app.consolidation.pamyat_nochnaya_proverka') . implode("\n\n", $report);
        // spec: brief/memory.md#консолидация-dreaming — a finding counts as shown only once the report reached the owner; an
        //       undelivered pass is a failed one, so the next night looks again even if memory did not change
        $delivered = ($this->tell)($text);
        if ($delivered) {
            foreach ($pending as [$kind, $a, $b]) {
                $this->journal->shown($kind, $a, $b);
            }
        }
        $this->journal->save($delivered ? ConsolidationJournal::DONE : ConsolidationJournal::FAILED, $count, $chars, self::ms($started), $report, $calls);
        return $text;
    }

    /**
     * One pair of near-copies a night: the weaker one is proposed to be forgotten.
     *
     * @param list<Fact> $facts
     */
    private function duplicate(array $facts): ?string {
        $pending = $this->busy();
        $checked = 0;
        foreach ($facts as $i => $a) {
            foreach (array_slice($facts, $i + 1) as $b) {
                if (++$checked > self::PAIR_BUDGET) {
                    return null;
                }
                if ($a->scope !== $b->scope || isset($pending[$a->id]) || isset($pending[$b->id]) || !self::duplicates($a, $b)) {
                    continue;
                }
                [$keep, $drop] = self::keep($a, $b);
                if (!$this->journal->due('duplicate', min($a->id, $b->id), max($a->id, $b->id), self::PAUSE_DUPLICATE)) {
                    continue;
                }
                try {
                    $proposal = $this->proposals->propose(
                        Proposal::FORGET,
                        $drop->id,
                        '',
                        sprintf(t('app.consolidation.nochnaya_proverka_povtoryaet_fakt'), $keep->id, self::cut($keep->text)),
                        [$drop->scope],
                        null,
                        null,
                    );
                } catch (InvalidArgumentException) {
                    continue;
                }
                // the card is its own message: the pair counts as shown once the card reached the owner
                if (($this->show)($proposal)) {
                    $this->journal->shown('duplicate', min($a->id, $b->id), max($a->id, $b->id));
                }
                return sprintf(t('app.consolidation.fakt_povtoryaet_predlozhil_zabyt_kartoch'), $drop->id, $keep->id, $drop->id, $proposal->id);
            }
        }
        return null;
    }

    /**
     * The gray zone asked of the model: copies become cards «forget» (within the cards left for the night), conflicts and
     * replacements go into the report, a detail added is a candidate to merge. When the model answered for fewer than
     * half of the pairs, the night stays the code's and says so.
     *
     * @param list<Fact> $facts
     * @param int $cards cards left for the night; what is left after the copies goes to merges
     * @param list<array{string, int, int}> $pending
     * @param-out list<array{string, int, int}> $pending
     * @return array{list<string>, array<string, true>} lines of the report, pairs the model settled (not to be shown as conflicts)
     */
    private function judged(array $facts, int &$cards, array &$pending): array {
        $this->merges = [];
        if ($this->judge === null) {
            return [[], []];
        }
        $gray = (new GrayZone())->pairs($facts);
        if ($gray === []) {
            return [[], []];
        }
        $verdicts = $this->judge->judge(array_map(static fn(array $p): array => [$p[0], $p[1]], $gray));
        $answered = count(array_filter($verdicts, static fn(?Verdict $v): bool => $v !== null));
        if ($answered * 2 < count($gray)) {
            return [[t('app.consolidation.model_silent')], []];
        }
        $lines = [];
        $settled = [];
        foreach ($gray as $i => [$a, $b]) {
            $verdict = $verdicts[$i] ?? null;
            if ($verdict === null || $verdict->confidence < self::MIN_CONFIDENCE) {
                continue;
            }
            $settled[self::pairKey($a, $b)] = true;
            switch ($verdict->label) {
                case Verdict::SAME:
                    [$keep, $drop] = self::keep($a, $b);
                    $card = $cards > 0 ? $this->forget($keep, $drop, 'app.consolidation.judged_same') : null;
                    if ($card !== null) {
                        $cards--;
                        $lines[] = sprintf(t('app.consolidation.fakt_povtoryaet_predlozhil_zabyt_kartoch'), $drop->id, $keep->id, $drop->id, $card);
                    }
                    break;
                case Verdict::ADDS_DETAIL:
                    $this->merges[] = [$a, $b];
                    break;
                case Verdict::CONTRADICTS:
                case Verdict::SUPERSEDES:
                    if (!$this->journal->due('judged', min($a->id, $b->id), max($a->id, $b->id), self::PAUSE_NOTICE)) {
                        break;
                    }
                    $pending[] = ['judged', min($a->id, $b->id), max($a->id, $b->id)];
                    [$new, $old] = $verdict->newer === 'a' ? [$a, $b] : [$b, $a];
                    $lines[] = $verdict->label === Verdict::SUPERSEDES
                        ? sprintf(t('app.consolidation.judged_supersedes'), $new->id, self::cut($new->text), $old->id, self::cut($old->text))
                        : sprintf(t('app.consolidation.judged_conflict'), $a->id, self::cut($a->text), $b->id, self::cut($b->text));
                    break;
            }
        }
        return [$lines, $settled];
    }

    /**
     * Pairs where one fact adds a detail to the other become cards «merge», within the cards left for the night: the
     * model writes one text in the language of the fact that stays, the code checks it (MergeGuard), the owner decides.
     * A pair of two places is not merged: the text would reach people of the other place. A shown pair rests as a copy.
     *
     * spec: brief/memory.md#слияние
     * @return list<string>
     */
    private function merged(int $cards): array {
        if ($this->merger === null || $cards <= 0 || $this->merges === []) {
            return [];
        }
        $pairs = [];
        $busy = $this->busy();
        foreach ($this->merges as [$a, $b]) {
            if ($a->scope !== $b->scope || isset($busy[$a->id]) || isset($busy[$b->id]) || !$this->journal->due('merge', min($a->id, $b->id), max($a->id, $b->id), self::PAUSE_DUPLICATE)) {
                continue;
            }
            $pairs[] = self::keep($a, $b);
            if (count($pairs) >= $cards) {
                break;
            }
        }
        if ($pairs === []) {
            return [];
        }
        $texts = $this->merger->merge($pairs);
        $lines = [];
        $refused = 0;
        foreach ($pairs as $i => [$keep, $drop]) {
            $text = $texts[$i] ?? null;
            if ($text === null) {
                continue;
            }
            if (MergeGuard::problem($keep, $drop, $text) !== null) {
                $refused++;
                continue;
            }
            try {
                $proposal = $this->proposals->proposeMerge($keep, $drop, $text, sprintf(t('app.consolidation.merge_reason'), $keep->id, $drop->id));
            } catch (InvalidArgumentException) {
                $refused++;
                continue;
            }
            if (($this->show)($proposal)) {
                $this->journal->shown('merge', min($keep->id, $drop->id), max($keep->id, $drop->id));
                $lines[] = sprintf(t('app.consolidation.merge_card'), $keep->id, $drop->id, $proposal->id);
            }
        }
        if ($refused > 0) {
            $lines[] = sprintf(t('app.consolidation.merge_refused'), $refused);
        }
        return $lines;
    }

    /** @return array<int, true> facts a pending card already asks about */
    private function busy(): array {
        $busy = [];
        foreach ($this->store->pending() as $proposal) {
            foreach ([$proposal->factId, $proposal->mergeWith] as $id) {
                if ($id !== null) {
                    $busy[$id] = true;
                }
            }
        }
        return $busy;
    }

    /** A card «forget» for the weaker of two copies; its number when it reached the owner. */
    private function forget(Fact $keep, Fact $drop, string $reason): ?int {
        if (!$this->journal->due('duplicate', min($keep->id, $drop->id), max($keep->id, $drop->id), self::PAUSE_DUPLICATE)) {
            return null;
        }
        try {
            $proposal = $this->proposals->propose(Proposal::FORGET, $drop->id, '', sprintf(t($reason), $keep->id, self::cut($keep->text)), [$drop->scope], null, null);
        } catch (InvalidArgumentException) {
            return null;
        }
        if (($this->show)($proposal)) {
            $this->journal->shown('duplicate', min($keep->id, $drop->id), max($keep->id, $drop->id));
            return $proposal->id;
        }
        return null;
    }

    private static function pairKey(Fact $a, Fact $b): string {
        return min($a->id, $b->id) . ':' . max($a->id, $b->id);
    }

    /**
     * Facts about the same thing with different numbers: which is right is the owner's call.
     *
     * @param list<Fact> $facts
     * @param list<array{string, int, int}> $pending
     * @param-out list<array{string, int, int}> $pending
     * @param array<string, true> $settled pairs the model settled: not shown as conflicts
     * @return list<string>
     */
    private function conflicts(array $facts, array &$pending, array $settled = []): array {
        $found = [];
        $checked = 0;
        foreach ($facts as $i => $a) {
            foreach (array_slice($facts, $i + 1) as $b) {
                if (++$checked > self::PAIR_BUDGET || count($found) >= self::LIST_CAP) {
                    break 2;
                }
                if ($a->scope !== $b->scope || isset($settled[self::pairKey($a, $b)]) || !self::conflict($a, $b) || !$this->journal->due('conflict', $a->id, $b->id, self::PAUSE_NOTICE)) {
                    continue;
                }
                $pending[] = ['conflict', $a->id, $b->id];
                $found[] = sprintf(
                    t(self::flip($a, $b) ? 'app.consolidation.conflict_negation' : 'app.consolidation.vozmozhno_protivorechat_drug_drugu_razny'),
                    $a->id,
                    self::cut($a->text),
                    $b->id,
                    self::cut($b->text),
                );
            }
        }
        return $found;
    }

    /**
     * Old facts of low trust that no run was ever given: a question, never a deletion.
     *
     * @param list<Fact> $facts
     * @param list<array{string, int, int}> $pending
     * @param-out list<array{string, int, int}> $pending
     * @return list<string>
     */
    private function unused(array $facts, array &$pending): array {
        $old = array_values(array_filter(
            $facts,
            fn(Fact $f): bool => $f->trust <= self::UNUSED_TRUST && $f->updatedAt < ($this->now)() - self::UNUSED_DAYS * 86400,
        ));
        if ($old === []) {
            return [];
        }
        $used = array_flip($this->store->recalledAny(array_map(static fn(Fact $f): int => $f->id, $old)));
        $lines = [];
        foreach ($old as $fact) {
            if (count($lines) >= self::LIST_CAP) {
                break;
            }
            if (!isset($used[$fact->id]) && $this->journal->due('unused', $fact->id, 0, self::PAUSE_NOTICE)) {
                $pending[] = ['unused', $fact->id, 0];
                $lines[] = sprintf('#%d %s', $fact->id, self::cut($fact->text));
            }
        }
        return $lines === [] ? [] : [t('app.consolidation.davno_ne_prigozhdalis_nuzhny_li') . implode("\n", $lines)];
    }

    /** Near-copies: the same numbers, and nearly the same words. */
    public static function duplicates(Fact $a, Fact $b): bool {
        if (self::numbers($a) !== self::numbers($b) || self::flip($a, $b)) {
            return false;
        }
        $shared = count(array_intersect($a->keys, $b->keys));
        $all = count(array_unique([...$a->keys, ...$b->keys]));
        return $all > 0 && min(count($a->keys), count($b->keys)) >= 2 && (float) $shared / (float) $all >= self::DUPLICATE_JACCARD;
    }

    /**
     * About the same thing, with numbers that differ or a word one of them negates.
     *
     * ref: references/hermes-dreaming/skills/dreaming/scripts/dream.py@21878d3:L3035-L3068 (find_conflicts) — a
     *      negation that differs is a conflict before the numbers are looked at
     */
    public static function conflict(Fact $a, Fact $b): bool {
        $numbersA = self::numbers($a);
        $numbersB = self::numbers($b);
        $wordsA = array_diff($a->keys, $numbersA);
        $wordsB = array_diff($b->keys, $numbersB);
        $shared = count(array_intersect($wordsA, $wordsB));
        $smaller = min(count($wordsA), count($wordsB));
        if ($shared < self::CONFLICT_SHARED || $smaller === 0 || (float) $shared / (float) $smaller < self::CONFLICT_OVERLAP) {
            return false;
        }
        return self::flip($a, $b) || ($numbersA !== [] && $numbersB !== [] && $numbersA !== $numbersB);
    }

    /** One of the two negates a word both speak of («любит острое» and «не любит острое»). */
    private static function flip(Fact $a, Fact $b): bool {
        if (!TextAnalyzer::negates($a->text) && !TextAnalyzer::negates($b->text)) {
            return false;
        }
        self::$analyzer ??= new TextAnalyzer();
        $negatedA = self::$analyzer->negated($a->text);
        $negatedB = self::$analyzer->negated($b->text);
        $differ = array_merge(array_diff($negatedA, $negatedB), array_diff($negatedB, $negatedA));
        return array_intersect($differ, $a->keys, $b->keys) !== [];
    }

    /**
     * Which of two copies stays: more keys, then the owner's word over the agent's, then more trust, then the older.
     *
     * @return array{Fact, Fact} keep, drop
     */
    private static function keep(Fact $a, Fact $b): array {
        $rank = static fn(Fact $f): array => [count($f->keys), $f->provenance === Fact::AGENT ? 0 : 1, $f->trust, -$f->id];
        return $rank($a) >= $rank($b) ? [$a, $b] : [$b, $a];
    }

    /** @return list<string> the keys with digits, sorted */
    private static function numbers(Fact $fact): array {
        $numbers = array_values(array_filter($fact->keys, static fn(string $k): bool => preg_match('~\d~', $k) === 1));
        sort($numbers);
        return $numbers;
    }

    private static function cut(string $text): string {
        $text = str_replace("\n", ' ', $text);
        return mb_strlen($text) > self::LINE ? mb_substr($text, 0, self::LINE) . '…' : $text;
    }

    private static function ms(int $started): int {
        return intdiv(hrtime(true) - $started, 1_000_000);
    }
}

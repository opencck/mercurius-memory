<?php

declare(strict_types=1);

namespace Mercurius\Memory\App;

use Closure;
use InvalidArgumentException;
use Mercurius\Memory\Domain\ConsolidationJournal;
use Mercurius\Memory\Domain\Fact;
use Mercurius\Memory\Domain\MemoryStore;
use Mercurius\Memory\Domain\Proposal;
use Mercurius\Memory\Domain\TextAnalyzer;

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

    /** @var Closure(): int */
    private readonly Closure $now;
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
        array_push($report, ...$this->conflicts($facts, $pending), ...$this->unused($facts, $pending));

        if ($report === []) {
            $this->journal->save(ConsolidationJournal::DONE, $count, $chars, self::ms($started), $report);
            return sprintf(t('app.consolidation.provereno_faktov_zamechaniy_net'), $count);
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
        $this->journal->save($delivered ? ConsolidationJournal::DONE : ConsolidationJournal::FAILED, $count, $chars, self::ms($started), $report);
        return $text;
    }

    /**
     * One pair of near-copies a night: the weaker one is proposed to be forgotten.
     *
     * @param list<Fact> $facts
     */
    private function duplicate(array $facts): ?string {
        $pending = [];
        foreach ($this->store->pending() as $proposal) {
            if ($proposal->factId !== null) {
                $pending[$proposal->factId] = true;
            }
        }
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
     * Facts about the same thing with different numbers: which is right is the owner's call.
     *
     * @param list<Fact> $facts
     * @param list<array{string, int, int}> $pending
     * @param-out list<array{string, int, int}> $pending
     * @return list<string>
     */
    private function conflicts(array $facts, array &$pending): array {
        $found = [];
        $checked = 0;
        foreach ($facts as $i => $a) {
            foreach (array_slice($facts, $i + 1) as $b) {
                if (++$checked > self::PAIR_BUDGET || count($found) >= self::LIST_CAP) {
                    break 2;
                }
                if ($a->scope !== $b->scope || !self::conflict($a, $b) || !$this->journal->due('conflict', $a->id, $b->id, self::PAUSE_NOTICE)) {
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

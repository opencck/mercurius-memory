<?php

declare(strict_types=1);

namespace Mercurius\Memory\App;

use Closure;
use Mercurius\Memory\Domain\Fact;
use Mercurius\Memory\Domain\GuardJournal;
use Mercurius\Memory\Domain\MemoryStore;
use Mercurius\Memory\Domain\Probes;
use Mercurius\Memory\Domain\SleepEdits;

use function Mercurius\Memory\t;

/**
 * The guard against a loss of memory: every night and on command, whether sleep runs or not, the active facts and their
 * characters are compared with the last snapshot; a sharp drop is told to the owner. A drop that was not delivered is
 * compared again next time: the snapshot it is measured from stays. Facts sleep ended by itself under the owner's
 * standing permission are its own edits, recorded and undoable, and do not count as a loss. The owner's probes are
 * asked too: a probe whose fact is gone or is no longer among the first found by its question is told once.
 *
 * spec: brief/memory.md#страж-потерь
 * spec: brief/memory.md#пробы-владельца
 * ref: references/hermes-dreaming/skills/dreaming/scripts/dream.py@46d2f9e:L4779-L4902 (check_memory_loss) — more than
 *      25% of entries or characters gone, counted from 4 entries or 400 characters
 * ref: references/hermes-dreaming/skills/dreaming/scripts/dream.py@46d2f9e:L4756-L4776 (probe_found, check_probes) —
 *      one alert per probe that went missing, quiet while it stays missing. Here a probe is a question and its fact,
 *      asked through search, not a phrase looked for in the text: what is lost is being found, not only being kept
 */
final class LossGuard {
    public const SHARE = 0.25;
    public const MIN_FACTS = 4;
    public const MIN_CHARS = 400;
    /** a probe passes when its fact is among the first this many found by its question */
    public const PROBE_TOP = 5;

    /**
     * @param Closure(string): bool $tell writes to the owner; false when the message was not delivered
     */
    public function __construct(
        private readonly MemoryStore $store,
        private readonly GuardJournal $journal,
        private readonly Closure $tell,
        private readonly ?SleepEdits $edits = null,
        private readonly ?Probes $probes = null,
        private readonly ?Recall $recall = null,
    ) {}

    /** @return string|null the alert, when there was a drop */
    public function run(): ?string {
        $facts = $this->store->active(Consolidation::FACTS);
        $count = count($facts);
        $chars = array_sum(array_map(static fn(Fact $f): int => mb_strlen($f->text), $facts));
        $last = $this->journal->last();
        // spec: brief/memory.md#постоянное-разрешение — what sleep ended by itself since the snapshot is not a loss
        [$ownFacts, $ownChars] = $last === null || $this->edits === null ? [0, 0] : $this->edits->since($last['at']);
        $alert = $last === null ? null : self::loss($last['facts'], $last['chars'], $count + $ownFacts, $chars + $ownChars);
        [$lines, $found, $missing] = $this->probe();
        foreach ($found as $id) {
            $this->probes?->mark($id, false);
        }
        $text = implode("

", array_values(array_filter([$alert, ...$lines], static fn(?string $l): bool => $l !== null)));
        if ($text === '') {
            $this->journal->save($count, $chars, false);
            return null;
        }
        $delivered = ($this->tell)($text);
        if ($alert === null || $delivered) {
            $this->journal->save($count, $chars, $alert !== null);
        }
        if ($delivered) {
            foreach ($missing as $id) {
                $this->probes?->mark($id, true);
            }
        }
        return $text;
    }

    /**
     * @return array{list<string>, list<int>, list<int>} lines about probes missing and not told yet; probes found that
     *         were missing; probes those lines are about
     */
    private function probe(): array {
        if ($this->probes === null || $this->recall === null) {
            return [[], [], []];
        }
        [$lines, $found, $missing] = [[], [], []];
        foreach ($this->probes->all() as $probe) {
            $fact = $this->store->fact($probe->factId);
            $gone = true;
            $ok = false;
            if ($fact !== null && $fact->active) {
                $gone = false;
                $first = array_slice($this->recall->find($probe->question, [$fact->scope]), 0, self::PROBE_TOP);
                $ok = in_array($probe->factId, array_map(static fn(Fact $f): int => $f->id, $first), true);
            }
            if ($ok) {
                if ($probe->missing) {
                    $found[] = $probe->id;
                }
                continue;
            }
            if (!$probe->missing) {
                $lines[] = sprintf(t($gone ? 'app.loss_guard.probe_gone' : 'app.loss_guard.probe_lost'), $probe->id, $probe->question, $probe->factId);
                $missing[] = $probe->id;
            }
        }
        return [$lines, $found, $missing];
    }

    public static function loss(int $facts, int $chars, int $nowFacts, int $nowChars): ?string {
        $byFacts = $facts >= self::MIN_FACTS && (float) $nowFacts < (float) $facts * (1.0 - self::SHARE);
        $byChars = $chars >= self::MIN_CHARS && (float) $nowChars < (float) $chars * (1.0 - self::SHARE);
        if (!$byFacts && !$byChars) {
            return null;
        }
        return sprintf(t('app.consolidation.pamyati_stalo_zametno_menshe_faktov'), $facts, $nowFacts, $chars, $nowChars);
    }
}

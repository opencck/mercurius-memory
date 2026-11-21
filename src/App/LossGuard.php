<?php

declare(strict_types=1);

namespace Mercurius\Memory\App;

use Closure;
use Mercurius\Memory\Domain\Fact;
use Mercurius\Memory\Domain\GuardJournal;
use Mercurius\Memory\Domain\MemoryStore;
use Mercurius\Memory\Domain\SleepEdits;

use function Mercurius\Memory\t;

/**
 * The guard against a loss of memory: every night and on command, whether sleep runs or not, the active facts and their
 * characters are compared with the last snapshot; a sharp drop is told to the owner. A drop that was not delivered is
 * compared again next time: the snapshot it is measured from stays. Facts sleep ended by itself under the owner's
 * standing permission are its own edits, recorded and undoable, and do not count as a loss.
 *
 * spec: brief/memory.md#страж-потерь
 * ref: references/hermes-dreaming/skills/dreaming/scripts/dream.py@21878d3:L4614-L4737 (check_memory_loss) — more than
 *      25% of entries or characters gone, counted from 4 entries or 400 characters
 */
final class LossGuard {
    public const SHARE = 0.25;
    public const MIN_FACTS = 4;
    public const MIN_CHARS = 400;

    /**
     * @param Closure(string): bool $tell writes to the owner; false when the message was not delivered
     */
    public function __construct(
        private readonly MemoryStore $store,
        private readonly GuardJournal $journal,
        private readonly Closure $tell,
        private readonly ?SleepEdits $edits = null,
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
        if ($alert === null) {
            $this->journal->save($count, $chars, false);
            return null;
        }
        if (($this->tell)($alert)) {
            $this->journal->save($count, $chars, true);
        }
        return $alert;
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

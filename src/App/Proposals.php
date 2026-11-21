<?php

declare(strict_types=1);

namespace Mercurius\Memory\App;

use Closure;
use InvalidArgumentException;
use Throwable;
use Mercurius\Memory\Domain\ContentScreen;
use Mercurius\Memory\Domain\Fact;
use Mercurius\Memory\Domain\MemoryStore;
use Mercurius\Memory\Domain\Proposal;
use Mercurius\Memory\Domain\TextAnalyzer;

use function Mercurius\Memory\t;

/**
 * Changes to memory go through the owner: the agent proposes, the owner accepts or rejects, and only an accepted
 * proposal changes memory, with a new version. A forgotten text leaves a keyed fingerprint, so it cannot come back.
 *
 * spec: brief/memory.md#как-сделано-области-и-факты — new facts and edits of layer 1 are proposals to the owner
 * ref: references/hermes-plugin-holographic/history.py@4a794f8:L489-L710 (change_fact, end_fact, forget_fact) —
 *      versions and tombstones; a proposal step in front of them is ours
 * ref: references/hermes-agent/tools/memory_tool.py@e96221fa:L56 — layer limits in characters, not tokens (2200 and
 *      1375 there; ours are larger: profile 2000, summary 4000)
 */
final class Proposals {
    public const PROFILE_MAX = 2000;
    public const SUMMARY_MAX = 4000;
    /** Trust of a fact the owner accepted. */
    private const CONFIRMED_TRUST = 0.8;
    /** a fact the agent wrote itself: below what the owner confirmed */
    private const AGENT_TRUST = 0.6;
    /** the person said it in words: more than the agent's guess, less than the owner's yes on a card */
    private const SAID_TRUST = 0.7;
    private const SOURCES = ['owner' => Fact::OWNER_SAID, 'inferred' => Fact::AGENT, 'tool' => Fact::TOOL];
    private const MAX_KEYWORDS = 10;

    /** @var list<Closure(Fact, list<array{string, string}>): void> */
    private array $forgetListeners = [];

    public function __construct(
        private readonly MemoryStore $store,
        private readonly TextAnalyzer $analyzer,
        private readonly ContentScreen $screen,
        private readonly string $key,
    ) {}

    /**
     * Called after a fact is forgotten, with the cards of the proposals about it, so the places that still show its
     * text (cards, transcripts) can drop it.
     *
     * @param Closure(Fact, list<array{string, string}>): void $listener
     */
    public function onForget(Closure $listener): void {
        $this->forgetListeners[] = $listener;
    }

    /**
     * @param list<string> $readable the audience's scopes; a new fact goes to the first of them
     * @param bool $aboutPerson the fact is about another person: only the owner's memory takes it
     * @param string $source where the fact came from: owner (the person said it), inferred (the agent concluded it),
     *                       tool (a tool result); '' — not said, the agent's
     * @param list<string> $keywords words to find the fact by, besides its own (synonyms, loans, names)
     * @throws InvalidArgumentException with the reason to show the model
     */
    public function propose(string $kind, ?int $factId, string $text, string $reason, array $readable, ?int $principalId, ?int $runId, bool $aboutPerson = false, string $edit = 'all', string $oldText = '', bool $project = false, string $source = '', array $keywords = [], bool $bySleep = false): Proposal {
        $text = trim($text);
        $reason = trim($reason);
        if ($readable === []) {
            throw new InvalidArgumentException(t('app.proposals.zdes_pamyat_nedostupna'));
        }
        // spec: brief/memory.md#запись-сведения-о-людях-и-забывание — what is said about other people is kept only for the owner
        if ($aboutPerson && !in_array(Audience::OWNER, $readable, true)) {
            throw new InvalidArgumentException(t('app.proposals.about_person_owner_only'));
        }
        $scope = $aboutPerson ? Audience::OWNER : $readable[0];
        // spec: brief/memory.md#проекты-задачи-и-отношение-участника-к-проекту — a fact of the chat's active project
        if ($project) {
            $projects = array_values(array_filter($readable, static fn(string $s): bool => str_starts_with($s, 'project:')));
            if ($projects === [] || $aboutPerson) {
                throw new InvalidArgumentException(t('app.proposals.no_project_here'));
            }
            $scope = $projects[0];
        }
        switch ($kind) {
            case Proposal::ADD:
                $this->check($text, ContentScreen::FACT_MAX);
                $this->notForgotten($text);
                break;
            case Proposal::CHANGE:
            case Proposal::END:
            case Proposal::FORGET:
                $fact = $factId === null ? null : $this->store->fact($factId);
                if ($fact === null || !$fact->active || !in_array($fact->scope, $readable, true)) {
                    throw new InvalidArgumentException(t('app.proposals.takogo_fakta_zdes_net'));
                }
                $scope = $fact->scope;
                if ($aboutPerson && $scope !== Audience::OWNER) {
                    throw new InvalidArgumentException(t('app.proposals.about_person_owner_only'));
                }
                if ($kind === Proposal::CHANGE) {
                    $this->check($text, ContentScreen::FACT_MAX);
                    $this->notForgotten($text);
                } else {
                    $text = $fact->text;
                }
                break;
            case Proposal::PROFILE:
            case Proposal::SUMMARY:
                if (!in_array(Audience::OWNER, $readable, true)) {
                    throw new InvalidArgumentException(t('app.proposals.profil_i_svodka_menyayutsya_tolko'));
                }
                // spec: brief/memory.md#профиль-и-сводка — one entry is added, replaced or removed; the card shows the result
                $text = CoreEntries::apply($this->store->core(MemoryContext::section($kind, Audience::OWNER)), $edit, $oldText, $text);
                $this->check($text, $kind === Proposal::PROFILE ? self::PROFILE_MAX : self::SUMMARY_MAX);
                $scope = Audience::OWNER;
                $factId = null;
                break;
            default:
                throw new InvalidArgumentException(t('app.proposals.neizvestnyy_vid_izmeneniya') . $kind);
        }
        if ($reason === '') {
            throw new InvalidArgumentException(t('app.proposals.nuzhna_prichina_vladelets_reshaet_po'));
        }
        // spec: brief/memory.md#факты — the real provenance and the agent's keywords
        if ($source !== '' && !isset(self::SOURCES[$source])) {
            throw new InvalidArgumentException(t('app.proposals.bad_source'));
        }
        $keywords = array_values(array_unique(array_filter(array_map(static fn(string $k): string => trim($k), $keywords), static fn(string $k): bool => $k !== '')));
        if (count($keywords) > self::MAX_KEYWORDS || array_filter($keywords, static fn(string $k): bool => mb_strlen($k) > 40) !== []) {
            throw new InvalidArgumentException(sprintf(t('app.proposals.too_many_keywords'), self::MAX_KEYWORDS));
        }
        // spec: brief/memory.md#постоянное-разрешение — a card of sleep is marked as sleep's: it goes to the owner and may be allowed for good
        $provenance = $bySleep ? Proposal::SLEEP : ($source === '' ? null : self::SOURCES[$source]);
        $proposal = $this->store->propose($kind, $kind === Proposal::ADD ? null : $factId, $scope, $text, mb_substr($reason, 0, 500), $principalId, $runId, $aboutPerson, $provenance, $keywords);
        if ($kind !== Proposal::ADD) {
            return $proposal;
        }
        // spec: brief/memory.md#запись-сведения-о-людях-и-забывание — the agent writes a new fact itself and the owner corrects
        //       after; a change or a removal of a fact still waits for the owner's card
        $written = $this->decide($proposal->id, true, true);
        return $written === null ? $proposal : ($this->store->proposal($proposal->id) ?? $written->proposal);
    }

    /**
     * A card to merge two facts of one place: the kept fact gets the text, the other is forgotten. Both versions are kept
     * with the proposal; an edit of either fact before the owner decides makes the merge refuse.
     *
     * spec: brief/memory.md#слияние
     * ref: references/hermes-dreaming/README.md@21878d3:L113-L117 — a merge goes with the versions the human was shown,
     *      and a text that changed since makes it refuse (changed_since). Here the model of sleep wrote the text and
     *      the code checked it (MergeGuard), where in hermes the agent writes it after the human's "yes"
     */
    public function proposeMerge(Fact $keep, Fact $drop, string $text, string $reason): Proposal {
        $text = trim($text);
        $now = [$this->store->fact($keep->id), $this->store->fact($drop->id)];
        foreach ($now as $fact) {
            if ($fact === null || !$fact->active) {
                throw new InvalidArgumentException(t('app.proposals.takogo_fakta_zdes_net'));
            }
        }
        if ($keep->id === $drop->id || $keep->scope !== $drop->scope) {
            throw new InvalidArgumentException(t('app.proposals.merge_other_place'));
        }
        $this->check($text, ContentScreen::FACT_MAX);
        $this->notForgotten($text);
        return $this->store->propose(Proposal::MERGE, $keep->id, $keep->scope, $text, mb_substr(trim($reason), 0, 500), null, null, false, Proposal::SLEEP, [], $drop->id, $keep->version, $drop->version);
    }

    /**
     * Why a text may not be a fact (a secret, too long, a text the owner had forgotten); null — it may.
     */
    public function problem(string $text): ?string {
        $problem = $this->screen->problem(trim($text), ContentScreen::FACT_MAX);
        if ($problem !== null) {
            return $problem;
        }
        return $this->store->forgotten($this->fingerprint(trim($text))) ? t('app.proposals.vladelets_velel_zabyt_etot_tekst') : null;
    }

    /**
     * The keys of a text and of the words beside it, as a fact gets them.
     *
     * @param list<string> $words
     * @return list<string>
     */
    public function keysOf(string $text, array $words = []): array {
        $keys = $this->analyzer->keys($text);
        foreach ($words as $word) {
            $keys = [...$keys, ...$this->analyzer->keys($word)];
        }
        return array_values(array_unique($keys));
    }

    /**
     * Accepts or rejects a pending proposal once; an accepted one is applied. Null when it is already decided.
     * A text forgotten since the proposal was made is rejected instead. If applying fails, the proposal goes back to
     * pending and the error goes on, so the owner can press again.
     */
    /** @param bool $byAgent the agent accepted its own new fact: it is kept as the agent's, not as confirmed */
    public function decide(int $id, bool $approve, bool $byAgent = false): ?Decision {
        $pending = $this->store->proposal($id);
        if ($approve && $pending !== null && in_array($pending->kind, [Proposal::ADD, Proposal::CHANGE], true)
            && $this->store->forgotten($this->fingerprint($pending->text))) {
            $rejected = $this->store->decide($id, Proposal::REJECTED);
            return $rejected === null ? null : new Decision($rejected, false, t('app.proposals.otkloneno_etot_tekst_veleno_zabyt'));
        }
        $proposal = $this->store->decide($id, $approve ? Proposal::APPROVED : Proposal::REJECTED);
        if ($proposal === null) {
            return null;
        }
        if (!$approve) {
            return new Decision($proposal, false, t('app.proposals.otkloneno'));
        }
        try {
            $applied = $this->apply($proposal, $byAgent);
        } catch (Throwable $e) {
            $this->store->reopen($proposal->id);
            throw $e;
        }
        return new Decision($proposal, $applied, $applied ? t('app.proposals.prinyato_pamyat_izmenena') : t($proposal->kind === Proposal::MERGE ? 'app.proposals.merge_stale' : 'app.proposals.prinyato_no_menyat_uzhe_nechego'));
    }

    private function apply(Proposal $proposal, bool $byAgent = false): bool {
        switch ($proposal->kind) {
            case Proposal::ADD:
                $provenance = $byAgent ? ($proposal->provenance ?? Fact::AGENT) : Fact::OWNER_CONFIRMED;
                $trust = match ($provenance) {
                    Fact::OWNER_CONFIRMED => self::CONFIRMED_TRUST,
                    Fact::OWNER_SAID => self::SAID_TRUST,
                    default => self::AGENT_TRUST,
                };
                $fact = $this->store->addFact($proposal->scope, Fact::KNOWLEDGE, $proposal->text, $this->keys($proposal), $trust, $provenance, $proposal->runId, $proposal->keywords);
                $this->store->linkProposal($proposal->id, $fact->id);
                return true;
            case Proposal::CHANGE:
                return $this->store->changeFact((int) $proposal->factId, $proposal->text, $this->keys($proposal), $proposal->reason, $byAgent ? ($proposal->provenance ?? Fact::AGENT) : Fact::OWNER_CONFIRMED, $proposal->keywords === [] ? null : $proposal->keywords) !== null;
            case Proposal::END:
                return $this->store->endFact((int) $proposal->factId, $proposal->reason);
            case Proposal::FORGET:
                return $this->forget((int) $proposal->factId);
            case Proposal::MERGE:
                return $this->merge($proposal);
            case Proposal::PROFILE:
            case Proposal::SUMMARY:
                $this->store->setCore(MemoryContext::section($proposal->kind, $proposal->scope), $proposal->text);
                return true;
        }
        return false;
    }

    /**
     * The kept fact takes the merged text and the keys of both, so what found either fact finds it; then the other is
     * forgotten. Nothing is done when either fact changed or went since the owner was shown them.
     *
     * spec: brief/memory.md#слияние
     */
    private function merge(Proposal $proposal): bool {
        $keep = $this->store->fact((int) $proposal->factId);
        $drop = $proposal->mergeWith === null ? null : $this->store->fact($proposal->mergeWith);
        if ($keep === null || $drop === null || !$keep->active || !$drop->active
            || $keep->version !== $proposal->factVersion || $drop->version !== $proposal->mergeVersion) {
            return false;
        }
        $keys = array_values(array_unique([...$this->analyzer->keys($proposal->text), ...$keep->keys, ...$drop->keys]));
        if ($this->store->changeFact($keep->id, $proposal->text, $keys, $proposal->reason, Fact::OWNER_CONFIRMED, array_values(array_unique([...$keep->words, ...$drop->words]))) === null) {
            return false;
        }
        return $this->forget($drop->id);
    }

    /**
     * The keys of a fact: those of its text and of the keywords the agent gave with it.
     *
     * @return list<string>
     */
    private function keys(Proposal $proposal): array {
        return $this->keysOf($proposal->text, $proposal->keywords);
    }

    /**
     * Removes the fact with its versions and the proposals about it; only a keyed fingerprint of its text stays.
     * Listeners then clear the cards and transcripts that still show it.
     */
    public function forget(int $factId): bool {
        $fact = $this->store->fact($factId);
        if ($fact === null) {
            return false;
        }
        $cards = $this->store->cards($factId);
        if (!$this->store->forgetFact($factId, $this->fingerprint($fact->text))) {
            return false;
        }
        foreach ($this->forgetListeners as $listener) {
            $listener($fact, $cards);
        }
        return true;
    }

    private function notForgotten(string $text): void {
        if ($this->store->forgotten($this->fingerprint($text))) {
            throw new InvalidArgumentException(t('app.proposals.vladelets_velel_zabyt_etot_tekst'));
        }
    }

    private function fingerprint(string $text): string {
        $normal = mb_strtolower(preg_replace('~\s+~u', ' ', trim(str_replace(['ё', 'Ё'], ['е', 'Е'], $text))) ?? $text);
        return hash_hmac('sha256', $normal, $this->key);
    }

    private function check(string $text, int $max): void {
        $problem = $this->screen->problem($text, $max);
        if ($problem !== null) {
            throw new InvalidArgumentException(t('app.proposals.ne_zapisyvayu') . $problem . '.');
        }
    }
}

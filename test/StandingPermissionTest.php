<?php

declare(strict_types=1);

namespace Mercurius\Memory\Test;

use Closure;
use Mercurius\Memory\App\Consolidation;
use Mercurius\Memory\App\LossGuard;
use Mercurius\Memory\App\Proposals;
use Mercurius\Memory\Domain\ContentScreen;
use Mercurius\Memory\Domain\Fact;
use Mercurius\Memory\Domain\Proposal;
use Mercurius\Memory\Domain\SleepEdit;
use Mercurius\Memory\Domain\SleepJudge;
use Mercurius\Memory\Domain\SleepMerger;
use Mercurius\Memory\Domain\TextAnalyzer;
use Mercurius\Memory\Domain\Verdict;
use Mercurius\Memory\Infrastructure\InMemoryConsolidationJournal;
use Mercurius\Memory\Infrastructure\InMemoryGuardJournal;
use Mercurius\Memory\Infrastructure\InMemoryMemoryStore;
use Mercurius\Memory\Infrastructure\InMemorySleepEdits;
use PHPUnit\Framework\TestCase;

use function Mercurius\Memory\t;

/**
 * The owner's standing permission: without it sleep only shows cards; with it a sure copy, merge or replacement is
 * made by sleep itself, ending a fact instead of forgetting it, in the report and undone by /dream undo. The loss guard
 * does not take sleep's own edits for a loss.
 *
 * spec: brief/memory.md#постоянное-разрешение
 */
final class StandingPermissionTest extends TestCase {
    private InMemoryMemoryStore $store;
    private TextAnalyzer $analyzer;
    private InMemorySleepEdits $edits;
    /** @var list<string> */
    private array $allowed = [];
    /** @var list<Proposal> */
    private array $shown = [];

    protected function setUp(): void {
        $this->store = new InMemoryMemoryStore();
        $this->analyzer = new TextAnalyzer();
        $this->edits = new InMemorySleepEdits();
    }

    private function add(string $text, string $provenance = Fact::OWNER_SAID): Fact {
        return $this->store->addFact('owner', Fact::KNOWLEDGE, $text, $this->analyzer->keys($text), 0.8, $provenance, null);
    }

    /**
     * @param (Closure(Fact, Fact): ?Verdict)|null $say
     * @param list<?string> $texts
     */
    private function sleep(?Closure $say = null, array $texts = []): Consolidation {
        $consolidation = new Consolidation($this->store, new Proposals($this->store, $this->analyzer, new ContentScreen(), 'k'), new InMemoryConsolidationJournal(), function (Proposal $p): bool {
            $this->shown[] = $p;
            return true;
        }, static fn(string $t): bool => true);
        if ($say !== null) {
            $model = new class($say, $texts) implements SleepJudge, SleepMerger {
                /**
                 * @param Closure(Fact, Fact): ?Verdict $say
                 * @param list<?string> $texts
                 */
                public function __construct(private readonly Closure $say, private readonly array $texts) {}

                public function judge(array $pairs): array {
                    return array_map(fn(array $p): ?Verdict => ($this->say)($p[0], $p[1]), $pairs);
                }

                public function merge(array $pairs): array {
                    return array_slice($this->texts, 0, count($pairs));
                }

                /** @return array{int, int, float} */
                public function spent(): array {
                    return [0, 0, 0.0];
                }
            };
            $consolidation->useJudge($model);
            $consolidation->useMerger($model);
        }
        $consolidation->useStandingPermission(fn(): array => $this->allowed, $this->edits);
        return $consolidation;
    }

    public function testWithoutPermissionACopyIsACardOfSleep(): void {
        $this->add('Владелец пьёт кофе без сахара по утрам');
        $this->add('Владелец пьёт кофе без сахара по утрам.');

        $this->sleep()->run(true);

        self::assertCount(1, $this->shown);
        self::assertSame([Proposal::FORGET, Proposal::SLEEP], [$this->shown[0]->kind, $this->shown[0]->provenance]);
        self::assertCount(2, $this->store->active(10));
    }

    public function testWithPermissionSleepEndsACopyByItselfAndTheOwnerUndoesIt(): void {
        $this->add('Владелец пьёт кофе без сахара по утрам');
        $copy = $this->add('Владелец пьёт кофе без сахара по утрам.');
        $this->allowed = [SleepEdit::COPY];
        $sleep = $this->sleep();

        $report = $sleep->run(true);

        self::assertSame([], $this->shown, 'no card');
        self::assertFalse($this->store->fact($copy->id)?->active, 'ended, not forgotten');
        self::assertStringContainsString('/dream undo 1', $report);

        self::assertSame(sprintf(t('app.consolidation.undone'), 1), $sleep->undo(1));
        self::assertTrue($this->store->fact($copy->id)?->active);
        self::assertSame(sprintf(t('app.consolidation.undo_none'), 1), $sleep->undo(1), 'once');
    }

    public function testASureReplacementInTheOwnersWordsEndsTheOldFactAndAnUnsureOneIsOnlyReported(): void {
        $old = $this->add('Владелец живёт в Москве');
        $new = $this->add('Владелец теперь живёт в Паттайе');
        $this->allowed = [SleepEdit::CONFLICT];

        $this->sleep(static fn(Fact $a, Fact $b): Verdict => new Verdict(Verdict::SUPERSEDES, 0.7, $a->id === $new->id ? 'a' : 'b'))->run(true);
        self::assertTrue($this->store->fact($old->id)?->active, 'not sure enough: the owner decides');

        $this->sleep(static fn(Fact $a, Fact $b): Verdict => new Verdict(Verdict::SUPERSEDES, 0.95, $a->id === $new->id ? 'a' : 'b'))->run(true);
        self::assertFalse($this->store->facts[$old->id]->active);
        self::assertTrue($this->store->facts[$new->id]->active);
    }

    public function testAReplacementTheAgentInferredIsNotSettledBySleep(): void {
        $old = $this->add('Владелец живёт в Москве');
        $new = $this->add('Владелец теперь живёт в Паттайе', Fact::AGENT);
        $this->allowed = [SleepEdit::CONFLICT];

        $this->sleep(static fn(Fact $a, Fact $b): Verdict => new Verdict(Verdict::SUPERSEDES, 0.95, $a->id === $new->id ? 'a' : 'b'))->run(true);

        self::assertTrue($this->store->fact($old->id)?->active);
    }

    public function testASureMergeIsMadeByItselfAndUndoneUnlessTheFactChangedSince(): void {
        $keep = $this->add('Сервер pve-01 стоит в Хельсинки, в стойке у окна');
        $drop = $this->add('Сервер pve-01 стоит в Хельсинки, у него 128 ГБ памяти');
        $this->allowed = [SleepEdit::MERGE];
        $merged = 'Сервер pve-01 стоит в Хельсинки, в стойке у окна, у него 128 ГБ памяти';
        $sleep = $this->sleep(static fn(): Verdict => new Verdict(Verdict::ADDS_DETAIL, 0.9), [$merged]);

        $sleep->run(true);

        self::assertSame([], $this->shown);
        $edit = $this->edits->find(1) ?? self::fail('no edit');
        self::assertSame(SleepEdit::MERGE, $edit->kind);
        $kept = (int) $edit->kept;
        $ended = (int) $edit->ended;
        self::assertEqualsCanonicalizing([$keep->id, $drop->id], [$kept, $ended]);
        $before = $this->store->fact($kept)?->text === $merged ? ($kept === $keep->id ? $keep->text : $drop->text) : null;
        self::assertNotNull($before, 'the kept fact has the merged text');
        self::assertFalse($this->store->fact($ended)?->active);
        $versions = $this->store->versions($kept);
        self::assertSame(Proposal::SLEEP, end($versions)['source'] ?? null);

        $sleep->undo(1);
        self::assertSame($before, $this->store->fact($kept)?->text, 'the text before the merge is back');
        self::assertTrue($this->store->fact($ended)?->active);
    }

    public function testUndoDoesNotOverwriteAFactChangedAfterTheMerge(): void {
        $this->add('Сервер pve-01 стоит в Хельсинки, в стойке у окна');
        $this->add('Сервер pve-01 стоит в Хельсинки, у него 128 ГБ памяти');
        $this->allowed = [SleepEdit::MERGE];
        $merged = 'Сервер pve-01 стоит в Хельсинки, в стойке у окна, у него 128 ГБ памяти';
        $sleep = $this->sleep(static fn(): Verdict => new Verdict(Verdict::ADDS_DETAIL, 0.9), [$merged]);
        $sleep->run(true);
        $kept = $this->edits->find(1)?->kept;
        self::assertNotNull($kept);
        $this->store->changeFact($kept, 'Сервер pve-01 переехал в Таллин', ['сервер'], 'переезд');

        self::assertSame(sprintf(t('app.consolidation.undo_moved_on'), 1, $kept), $sleep->undo(1));
        self::assertSame('Сервер pve-01 переехал в Таллин', $this->store->fact($kept)?->text);
    }

    public function testTheLossGuardDoesNotTakeSleepsOwnEditsForALoss(): void {
        $facts = [];
        foreach (['Первый факт о сервере', 'Второй факт о сервере', 'Третий факт о сервере', 'Четвёртый факт о сервере'] as $text) {
            $facts[] = $this->add($text);
        }
        $journal = new InMemoryGuardJournal();
        $journal->now = static fn(): int => time() - 60;
        $told = [];
        $guard = new LossGuard($this->store, $journal, static function (string $t) use (&$told): bool {
            $told[] = $t;
            return true;
        }, $this->edits);
        $guard->run();
        foreach (array_slice($facts, 0, 2) as $fact) {
            $this->store->endFact($fact->id, 'копия');
            $this->edits->record(SleepEdit::COPY, null, null, $fact->id, mb_strlen($fact->text));
        }

        self::assertNull($guard->run());
        self::assertSame([], $told);
    }
}

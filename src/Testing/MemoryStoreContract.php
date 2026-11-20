<?php

declare(strict_types=1);

namespace Mercurius\Memory\Testing;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Memory\Domain\Fact;
use Mercurius\Memory\Domain\Hrr;
use Mercurius\Memory\Domain\MemoryStore;
use Mercurius\Memory\Domain\Proposal;

/**
 * Behaviour every memory store must have: the in-memory fake locally, PostgreSQL on the server.
 */
abstract class MemoryStoreContract extends AsyncTestCase {
    protected MemoryStore $store;
    protected int $conversationId;
    /** a unique scope per test: the database is shared */
    protected string $scope;

    protected function setUp(): void {
        parent::setUp();
        $this->scope = 'principal:' . random_int(100_000, 999_999_999);
    }

    public function testFactsAreFoundByKeysWithinTheirScope(): void {
        $fact = $this->store->addFact($this->scope, Fact::KNOWLEDGE, 'Сервер pve-01 стоит в Хельсинки', ['сервер', 'pve-01', 'хельсинк'], 0.8, Fact::OWNER_SAID, null);
        $this->store->addFact('chat:' . $this->scope, Fact::KNOWLEDGE, 'Другая область', ['сервер'], 0.8, Fact::OWNER_SAID, null);

        $found = $this->store->candidates([$this->scope], ['pve-01', 'нечто'], 10);

        self::assertSame([$fact->id], array_map(static fn(Fact $f): int => $f->id, $found));
        self::assertSame(['сервер', 'pve-01', 'хельсинк'], $found[0]->keys);
        self::assertTrue($found[0]->active);
        self::assertSame(1, $found[0]->version);
    }

    public function testVectorsFollowTheKeys(): void {
        $server = $this->store->addFact($this->scope, Fact::KNOWLEDGE, 'Сервер pve-01 в Хельсинки', ['сервер', 'pve-01', 'хельсинк'], 0.8, Fact::OWNER_SAID, null);
        $coffee = $this->store->addFact($this->scope, Fact::KNOWLEDGE, 'Кофе без сахара', ['коф', 'сахар'], 0.8, Fact::OWNER_SAID, null);
        $query = Hrr::phasor(Hrr::query(['сервер', 'хельсинк']));

        $sims = $this->store->similarities([$server->id, $coffee->id], $query);
        self::assertEqualsWithDelta(Hrr::similarity(Hrr::fact($server->keys), Hrr::query(['сервер', 'хельсинк'])), $sims[$server->id] ?? 0.0, 1e-4);
        self::assertLessThan(0.2, $sims[$coffee->id] ?? 1.0);

        $probes = array_map(Hrr::phasor(...), Hrr::probes('pve-01'));
        self::assertSame([$server->id], array_map(static fn(Fact $f): int => $f->id, $this->store->related([$this->scope], $probes, Hrr::RELATED_THRESHOLD, 10)));
        self::assertSame([], $this->store->related(['chat:' . $this->scope], $probes, Hrr::RELATED_THRESHOLD, 10));

        $this->store->changeFact($server->id, 'Сервер pve-02 в Хельсинки', ['сервер', 'pve-02', 'хельсинк'], 'переехал');
        self::assertSame([], $this->store->related([$this->scope], $probes, Hrr::RELATED_THRESHOLD, 10), 'the vector follows the new keys');
    }

    public function testFactsUnderTheTrustThresholdAreListedWeakestFirst(): void {
        // spec: brief/memory.md#факты — the weekly review shows facts that fell under the threshold
        $weak = $this->store->addFact($this->scope, Fact::KNOWLEDGE, 'слабый', ['слаб'], 0.1, Fact::AGENT, null);
        $weaker = $this->store->addFact($this->scope, Fact::KNOWLEDGE, 'слабее', ['слабе'], 0.05, Fact::AGENT, null);
        $this->store->addFact($this->scope, Fact::KNOWLEDGE, 'крепкий', ['крепк'], 0.9, Fact::AGENT, null);
        $this->store->addFact('chat:other', Fact::KNOWLEDGE, 'чужой слабый', ['чуж'], 0.1, Fact::AGENT, null);

        $faded = array_map(static fn(Fact $f): int => $f->id, $this->store->faded([$this->scope], 0.3, 10));

        self::assertSame([$weaker->id, $weak->id], array_values(array_intersect($faded, [$weak->id, $weaker->id])));
        self::assertCount(2, array_filter($this->store->faded([$this->scope], 0.3, 10), static fn(Fact $f): bool => in_array($f->text, ['слабый', 'слабее', 'крепкий', 'чужой слабый'], true)));
    }

    public function testUsageCountsTheRecallsOfAFact(): void {
        // spec: brief/memory.md#факты — how often a fact went into answers
        $fact = $this->store->addFact($this->scope, Fact::KNOWLEDGE, 'Выдача', ['выдач'], 0.5, Fact::AGENT, null);

        self::assertSame(['recalled' => 0, 'helpful' => 0, 'unhelpful' => 0], $this->store->usage($fact->id));
        $this->store->markRecalled($this->conversationId, [$fact->id]);
        $this->store->markRecalled($this->conversationId, [$fact->id]);
        self::assertSame(2, $this->store->usage($fact->id)['recalled']);
    }

    public function testFactsAreKeyedAgainWhenTheAnalyzerChanges(): void {
        // spec: brief/memory.md#анализ-текста — facts written before a new rule of the analyzer get its keys too
        $fact = $this->store->addFact($this->scope, Fact::KNOWLEDGE, 'Настроен VPN', ['настро', 'vpn'], 0.5, Fact::AGENT, null);
        $keys = static fn(string $text): array => $text === 'Настроен VPN' ? ['настро', 'vpn', 'loan:vpn'] : [];

        self::assertGreaterThanOrEqual(1, $this->store->rekey($keys));
        $after = $this->store->fact($fact->id);
        self::assertNotNull($after);
        self::assertSame(['настро', 'vpn', 'loan:vpn'], $after->keys);
        self::assertSame(1, $after->version, 'not a new version');
        self::assertSame(0, $this->store->rekey($keys), 'nothing left to change');
    }

    public function testEachVersionKeepsWhoMadeIt(): void {
        // spec: brief/memory.md#факты — the source of a version
        $fact = $this->store->addFact($this->scope, Fact::KNOWLEDGE, 'было', ['было'], 0.5, Fact::OWNER_SAID, null);
        $this->store->changeFact($fact->id, 'стало', ['стало'], 'уточнил', Fact::AGENT);

        self::assertSame(
            [[1, 'было', Fact::OWNER_SAID], [2, 'стало', Fact::AGENT]],
            array_map(static fn(array $v): array => [$v['version'], $v['text'], $v['source']], $this->store->versions($fact->id)),
        );
    }

    public function testActiveFactsAndRecalledOnes(): void {
        $a = $this->store->addFact($this->scope, Fact::KNOWLEDGE, 'a', ['a'], 0.5, Fact::AGENT, null);
        $b = $this->store->addFact('chat:' . $this->scope, Fact::KNOWLEDGE, 'b', ['b'], 0.5, Fact::AGENT, null);
        $c = $this->store->addFact($this->scope, Fact::KNOWLEDGE, 'c', ['c'], 0.5, Fact::AGENT, null);
        $this->store->endFact($c->id, 'устарел');
        $this->store->markRecalled($this->conversationId, [$b->id]);

        $ids = array_map(static fn(Fact $f): int => $f->id, $this->store->active(10_000));
        self::assertContains($a->id, $ids);
        self::assertContains($b->id, $ids);
        self::assertNotContains($c->id, $ids);
        self::assertSame([$b->id], $this->store->recalledAny([$a->id, $b->id]));
    }

    public function testFrequenciesCountWithinTheScope(): void {
        $this->store->addFact($this->scope, Fact::KNOWLEDGE, 'a', ['сервер', 'диск'], 0.5, Fact::AGENT, null);
        $this->store->addFact($this->scope, Fact::KNOWLEDGE, 'b', ['сервер'], 0.5, Fact::AGENT, null);

        $df = $this->store->frequencies([$this->scope], ['сервер', 'диск', 'нет']);

        self::assertSame(2, $df['сервер']);
        self::assertSame(1, $df['диск']);
        self::assertSame(0, $df['нет'] ?? 0);
        self::assertSame(2, $df['']);
    }

    public function testChangeEndAndForget(): void {
        $fact = $this->store->addFact($this->scope, Fact::KNOWLEDGE, 'старое', ['стар'], 0.5, Fact::AGENT, null);

        $changed = $this->store->changeFact($fact->id, 'новое', ['нов'], 'исправление');
        self::assertNotNull($changed);
        self::assertSame(2, $changed->version);
        self::assertSame('новое', $changed->text);
        self::assertSame([], $this->store->candidates([$this->scope], ['стар'], 10), 'keys follow the text');

        self::assertTrue($this->store->endFact($fact->id, 'больше не так'));
        self::assertSame([], $this->store->candidates([$this->scope], ['нов'], 10), 'an ended fact is not recalled');

        $other = $this->store->addFact($this->scope, Fact::KNOWLEDGE, 'секрет', ['секрет'], 0.5, Fact::AGENT, null);
        self::assertTrue($this->store->forgetFact($other->id, 'fp-' . $this->scope));
        self::assertNull($this->store->fact($other->id));
        self::assertTrue($this->store->forgotten('fp-' . $this->scope));
        self::assertFalse($this->store->forgotten('fp-other-' . $this->scope));
    }

    public function testProposalIsDecidedOnce(): void {
        $proposal = $this->store->propose(Proposal::ADD, null, $this->scope, 'Владелец пьёт кофе без сахара', 'сказал сам', null, null);

        self::assertSame(Proposal::PENDING, $proposal->status);
        self::assertContains($proposal->id, array_map(static fn(Proposal $p): int => $p->id, $this->store->pending()));
        self::assertSame(Proposal::APPROVED, $this->store->decide($proposal->id, Proposal::APPROVED)?->status);
        self::assertNull($this->store->decide($proposal->id, Proposal::REJECTED), 'already decided');
        self::assertNotContains($proposal->id, array_map(static fn(Proposal $p): int => $p->id, $this->store->pending()));
    }

    public function testForgettingRemovesProposalsAboutTheFact(): void {
        $text = 'Подсказка к PIN ' . $this->scope;
        $added = $this->store->propose(Proposal::ADD, null, $this->scope, $text, 'r', null, null);
        $this->store->decide($added->id, Proposal::APPROVED);
        $fact = $this->store->addFact($this->scope, Fact::KNOWLEDGE, $text, ['pin'], 0.8, Fact::OWNER_CONFIRMED, null);
        $this->store->linkProposal($added->id, $fact->id);
        $this->store->setCard($added->id, '1', '10');
        $again = $this->store->propose(Proposal::ADD, null, $this->scope, $text, 'r', null, null);
        $this->store->setCard($again->id, '1', '11');

        self::assertEqualsCanonicalizing([['1', '10'], ['1', '11']], $this->store->cards($fact->id));
        self::assertTrue($this->store->forgetFact($fact->id, 'fp2-' . $this->scope));
        self::assertNull($this->store->proposal($added->id));
        self::assertNull($this->store->proposal($again->id));
    }

    public function testEndedFactIsNotChangedAndApprovalCanBeReopened(): void {
        $fact = $this->store->addFact($this->scope, Fact::KNOWLEDGE, 'было', ['был'], 0.5, Fact::AGENT, null);
        $this->store->endFact($fact->id, 'всё');
        self::assertNull($this->store->changeFact($fact->id, 'стало', ['стал'], 'x'));

        $p = $this->store->propose(Proposal::SUMMARY, null, $this->scope, 'сводка', 'r', null, null);
        $this->store->decide($p->id, Proposal::APPROVED);
        $this->store->reopen($p->id);
        self::assertSame(Proposal::PENDING, $this->store->proposal($p->id)?->status);
    }

    public function testCoreKeepsTheLatestVersion(): void {
        $before = $this->store->core('summary');
        $this->store->setCore('summary', 'Первая ' . $this->scope);
        $this->store->setCore('summary', 'Вторая ' . $this->scope);

        self::assertSame('Вторая ' . $this->scope, $this->store->core('summary'));
        self::assertNotSame($before, $this->store->core('summary'));
    }

    public function testRecallWindow(): void {
        $a = $this->store->addFact($this->scope, Fact::KNOWLEDGE, 'a', ['a1'], 0.5, Fact::AGENT, null);
        $b = $this->store->addFact($this->scope, Fact::KNOWLEDGE, 'b', ['b1'], 0.5, Fact::AGENT, null);

        $this->store->markRecalled($this->conversationId, [$a->id]);
        $this->store->markRecalled($this->conversationId, [$b->id]);

        self::assertEqualsCanonicalizing([$a->id, $b->id], $this->store->recalled($this->conversationId, 20));
        self::assertSame([$b->id], $this->store->recalled($this->conversationId, 1), 'only the latest recalls');
    }

    public function testRatingMovesTrustWithinBounds(): void {
        $fact = $this->store->addFact($this->scope, Fact::KNOWLEDGE, 'c', ['c1'], 0.5, Fact::AGENT, null);

        self::assertEqualsWithDelta(0.55, $this->store->rateFact($fact->id, 0.05, true), 0.0001);
        for ($i = 0; $i < 7; $i++) {
            $trust = $this->store->rateFact($fact->id, -0.1, false);
        }
        self::assertEqualsWithDelta(0.0, $trust ?? -1.0, 0.0001, 'never below zero');
        self::assertEqualsWithDelta(0.0, $this->store->fact($fact->id)->trust ?? -1.0, 0.0001);

        $this->store->endFact($fact->id, 'old');
        self::assertNull($this->store->rateFact($fact->id, 0.05, true), 'an ended fact is not rated');
    }
}

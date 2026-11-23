<?php

declare(strict_types=1);

namespace Mercurius\Memory\Test;

use Mercurius\Memory\App\LossGuard;
use Mercurius\Memory\App\Recall;
use Mercurius\Memory\Domain\Fact;
use Mercurius\Memory\Domain\Probes;
use Mercurius\Memory\Domain\TextAnalyzer;
use Mercurius\Memory\Infrastructure\InMemoryGuardJournal;
use Mercurius\Memory\Infrastructure\InMemoryMemoryStore;
use Mercurius\Memory\Infrastructure\InMemoryProbes;
use PHPUnit\Framework\TestCase;

use function Mercurius\Memory\t;

/**
 * The owner's probes: a question memory must always answer with its fact. The guard asks each one every night; a probe
 * whose fact is gone or no longer found is told once, and once more only after it was found again and lost again.
 *
 * spec: brief/memory.md#пробы-владельца
 */
final class ProbesTest extends TestCase {
    private InMemoryMemoryStore $store;
    private TextAnalyzer $analyzer;
    private InMemoryProbes $probes;
    /** @var list<string> */
    private array $told = [];
    private bool $delivered = true;

    protected function setUp(): void {
        $this->store = new InMemoryMemoryStore();
        $this->analyzer = new TextAnalyzer();
        $this->probes = new InMemoryProbes();
    }

    private function add(string $text): Fact {
        return $this->store->addFact('owner', Fact::KNOWLEDGE, $text, $this->analyzer->keys($text), 0.8, Fact::OWNER_SAID, null);
    }

    private function guard(): LossGuard {
        return new LossGuard($this->store, new InMemoryGuardJournal(), function (string $t): bool {
            $this->told[] = $t;
            return $this->delivered;
        }, null, $this->probes, new Recall($this->store, $this->analyzer));
    }

    public function testAProbeFoundStaysQuietAndAGoneFactIsToldOnce(): void {
        $coffee = $this->add('Владелец пьёт кофе без сахара');
        $this->add('Сервер pve-01 стоит в Хельсинки');
        $probe = $this->probes->add('какой кофе пьёт владелец', $coffee->id);
        $guard = $this->guard();

        self::assertNull($guard->run(), 'found: nothing to tell');

        $this->store->endFact($coffee->id, 'слито');
        $alert = $guard->run();
        self::assertSame(sprintf(t('app.loss_guard.probe_gone'), $probe->id, 'какой кофе пьёт владелец', $coffee->id), $alert);
        self::assertNull($guard->run(), 'still missing: told already');
        self::assertCount(1, $this->told);
    }

    public function testAFactNoLongerFoundByItsQuestionIsToldAndAnUndeliveredAlertComesAgain(): void {
        $coffee = $this->add('Владелец пьёт кофе без сахара');
        $probe = $this->probes->add('какой кофе пьёт владелец', $coffee->id);
        $guard = $this->guard();
        $guard->run();
        $this->store->changeFact($coffee->id, 'Владелец предпочитает чай', $this->analyzer->keys('Владелец предпочитает чай'), 'владелец сказал');
        $this->delivered = false;

        $expected = sprintf(t('app.loss_guard.probe_lost'), $probe->id, 'какой кофе пьёт владелец', $coffee->id);
        self::assertSame($expected, $guard->run());
        $this->delivered = true;
        self::assertSame($expected, $guard->run(), 'not delivered: told again next time');
        self::assertNull($guard->run());

        $this->store->changeFact($coffee->id, 'Владелец пьёт кофе без сахара', $this->analyzer->keys('Владелец пьёт кофе без сахара'), 'владелец сказал');
        self::assertNull($guard->run(), 'found again');
        self::assertFalse($this->probes->all()[0]->missing);
    }

    public function testTheListIsKeptShort(): void {
        $fact = $this->add('Владелец пьёт кофе без сахара');
        for ($i = 0; $i < Probes::MAX; $i++) {
            $this->probes->add('вопрос ' . $i, $fact->id);
        }

        $this->expectException(\LengthException::class);
        $this->probes->add('ещё вопрос', $fact->id);
    }
}

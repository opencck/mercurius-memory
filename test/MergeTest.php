<?php

declare(strict_types=1);

namespace Mercurius\Memory\Test;

use InvalidArgumentException;
use Mercurius\Memory\App\MergeGuard;
use Mercurius\Memory\App\Proposals;
use Mercurius\Memory\Domain\ContentScreen;
use Mercurius\Memory\Domain\Fact;
use Mercurius\Memory\Domain\Proposal;
use Mercurius\Memory\Domain\TextAnalyzer;
use Mercurius\Memory\Infrastructure\InMemoryMemoryStore;
use PHPUnit\Framework\TestCase;

use function Mercurius\Memory\t;

/**
 * Two facts into one: the code refuses a text that lost or added a number or an identifier, grew or changed script;
 * an accepted merge rewrites the kept fact with the keys of both and forgets the other, unless either changed since.
 *
 * spec: brief/memory.md#слияние
 */
final class MergeTest extends TestCase {
    private InMemoryMemoryStore $store;
    private TextAnalyzer $analyzer;
    private Proposals $proposals;

    protected function setUp(): void {
        $this->store = new InMemoryMemoryStore();
        $this->analyzer = new TextAnalyzer();
        $this->proposals = new Proposals($this->store, $this->analyzer, new ContentScreen(), 'k');
    }

    private function add(string $text, string $scope = 'owner'): Fact {
        return $this->store->addFact($scope, Fact::KNOWLEDGE, $text, $this->analyzer->keys($text), 0.8, Fact::OWNER_SAID, null);
    }

    public function testTheGuardRefusesALostOrAddedNumberAGrowthAndAnotherScript(): void {
        $keep = $this->add('Сервер pve-01 стоит в Хельсинки');
        $drop = $this->add('У pve-01 128 ГБ памяти');

        self::assertNull(MergeGuard::problem($keep, $drop, 'Сервер pve-01 стоит в Хельсинки, у него 128 ГБ памяти.'));
        self::assertSame(MergeGuard::LOST, MergeGuard::problem($keep, $drop, 'Сервер pve-01 стоит в Хельсинки, памяти много.'));
        self::assertSame(MergeGuard::LOST, MergeGuard::problem($keep, $drop, 'Сервер стоит в Хельсинки, у него 128 ГБ памяти.'), 'the identifier');
        self::assertSame(MergeGuard::ADDED, MergeGuard::problem($keep, $drop, 'Сервер pve-01 стоит в Хельсинки, 128 ГБ памяти и 2 диска.'));
        self::assertSame(MergeGuard::LOST, MergeGuard::problem($keep, $drop, 'Сервер pve-01 стоит в Хельсинки, 12 ГБ памяти.'), 'a number is no part of another');
        self::assertSame(MergeGuard::SCRIPT, MergeGuard::problem($keep, $drop, 'Server pve-01 is in Helsinki with 128 GB of memory.'));
        self::assertSame(MergeGuard::LONG, MergeGuard::problem($keep, $drop, str_repeat('Сервер pve-01 в Хельсинки, 128 ГБ. ', 5)));
        self::assertSame(MergeGuard::EMPTY, MergeGuard::problem($keep, $drop, '  '));
    }

    public function testAnAcceptedMergeRewritesTheKeptFactAndForgetsTheOther(): void {
        $keep = $this->add('Сервер pve-01 стоит в Хельсинки');
        $drop = $this->add('У pve-01 128 ГБ оперативной памяти');
        $merge = $this->proposals->proposeMerge($keep, $drop, 'Сервер pve-01 стоит в Хельсинки, 128 ГБ памяти', 'сон');

        $decision = $this->proposals->decide($merge->id, true);

        self::assertTrue($decision?->applied);
        $kept = $this->store->fact($keep->id);
        self::assertNotNull($kept);
        self::assertSame('Сервер pve-01 стоит в Хельсинки, 128 ГБ памяти', $kept->text);
        self::assertSame($keep->version + 1, $kept->version);
        $versions = $this->store->versions($keep->id);
        self::assertSame(Fact::OWNER_CONFIRMED, end($versions)['source'] ?? null);
        foreach ($drop->keys as $key) {
            self::assertContains($key, $kept->keys, 'what found the forgotten fact finds the kept one');
        }
        self::assertNull($this->store->fact($drop->id));
    }

    public function testAMergeRefusesWhenAFactChangedSince(): void {
        $keep = $this->add('Сервер pve-01 стоит в Хельсинки');
        $drop = $this->add('У pve-01 128 ГБ памяти');
        $merge = $this->proposals->proposeMerge($keep, $drop, 'Сервер pve-01 стоит в Хельсинки, 128 ГБ памяти', 'сон');
        $this->store->changeFact($drop->id, 'У pve-01 256 ГБ памяти', ['памят'], 'докупили');

        $decision = $this->proposals->decide($merge->id, true);

        self::assertNotNull($decision);
        self::assertFalse($decision->applied);
        self::assertSame(t('app.proposals.merge_stale'), $decision->outcome);
        self::assertSame('Сервер pve-01 стоит в Хельсинки', $this->store->fact($keep->id)?->text);
        self::assertNotNull($this->store->fact($drop->id));
    }

    public function testFactsOfTwoPlacesAreNotMerged(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->proposals->proposeMerge($this->add('Сервер pve-01 в Хельсинки'), $this->add('У pve-01 128 ГБ', 'project:1'), 'Сервер pve-01 в Хельсинки, 128 ГБ', 'сон');
    }

    public function testAMergeIsNotAKindTheAgentMayAskFor(): void {
        self::assertNotContains(Proposal::MERGE, Proposal::KINDS);
    }
}

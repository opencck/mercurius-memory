<?php

declare(strict_types=1);

namespace Mercurius\Memory\Test;

use Mercurius\Memory\App\Aliases;
use Mercurius\Memory\App\Recall;
use Mercurius\Memory\Domain\ContentScreen;
use Mercurius\Memory\Domain\Fact;
use Mercurius\Memory\Domain\TextAnalyzer;
use Mercurius\Memory\Infrastructure\InMemoryAliasStore;
use Mercurius\Memory\Infrastructure\InMemoryMemoryStore;
use PHPUnit\Framework\TestCase;

/**
 * The owner's table of words that mean one thing: a group joins words no stem or table would, facts are keyed again
 * when it changes, and short or secret words are refused.
 *
 * spec: brief/memory.md#словари
 */
final class AliasesTest extends TestCase {
    public function testAGroupFindsAFactByAnotherWord(): void {
        $memory = new InMemoryMemoryStore();
        $analyzer = new TextAnalyzer();
        $fact = $memory->addFact('owner', Fact::KNOWLEDGE, 'Кластер прокс живёт в стойке у окна', $analyzer->keys('Кластер прокс живёт в стойке у окна'), 0.8, Fact::OWNER_SAID, null);
        $recall = new Recall($memory, $analyzer);
        $before = $recall->find('где стоит proxmox', ['owner']);
        self::assertSame([], $before, 'no stem or table joins the two words');

        $aliases = new Aliases(new InMemoryAliasStore(), $analyzer, $memory, new ContentScreen());
        [$changed, $said] = $aliases->add(['прокс', 'Proxmox'], 7);

        self::assertTrue($changed, $said);
        $found = $recall->find('где стоит proxmox', ['owner']);
        self::assertCount(1, $found, 'the facts were keyed again');
        self::assertSame($fact->id, $found[0]->id);
        self::assertStringContainsString('прокс, proxmox', $aliases->list());
    }

    public function testAWordOfAGroupBringsTheOthersToIt(): void {
        $aliases = new Aliases(new InMemoryAliasStore(), new TextAnalyzer(), new InMemoryMemoryStore(), new ContentScreen());
        $aliases->add(['прокс', 'proxmox'], null);

        [$changed] = $aliases->add(['proxmox', 'pve'], null);

        self::assertTrue($changed);
        self::assertSame('#1: прокс, proxmox, pve', $aliases->list());
        self::assertFalse($aliases->add(['прокс', 'pve'], null)[0], 'nothing new');
    }

    public function testShortSecretAndLonelyWordsAreRefused(): void {
        $aliases = new Aliases(new InMemoryAliasStore(), new TextAnalyzer(), new InMemoryMemoryStore(), new ContentScreen(static fn(string $t): bool => str_contains($t, 'sk-')));

        self::assertFalse($aliases->add(['вп', 'vpn'], null)[0]);
        self::assertFalse($aliases->add(['ключ', 'sk-abcdef'], null)[0]);
        self::assertFalse($aliases->add(['одно'], null)[0]);
        self::assertTrue($aliases->add(['备份', 'бэкап'], null)[0], 'a Chinese word of two characters is a word');
    }

    public function testRemovingAGroupKeysFactsAgain(): void {
        $memory = new InMemoryMemoryStore();
        $analyzer = new TextAnalyzer();
        $aliases = new Aliases(new InMemoryAliasStore(), $analyzer, $memory, new ContentScreen());
        $aliases->add(['прокс', 'proxmox'], null);
        $memory->addFact('owner', Fact::KNOWLEDGE, 'Кластер прокс в стойке', $analyzer->keys('Кластер прокс в стойке'), 0.8, Fact::OWNER_SAID, null);

        self::assertTrue($aliases->remove(1)[0]);

        self::assertNotContains('alias:1', $memory->active(10)[0]->keys);
        self::assertFalse($aliases->remove(1)[0]);
    }
}

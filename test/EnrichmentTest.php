<?php

declare(strict_types=1);

namespace Mercurius\Memory\Test;

use Mercurius\Memory\App\Aliases;
use Mercurius\Memory\App\Enrichment;
use Mercurius\Memory\App\Proposals;
use Mercurius\Memory\App\Recall;
use Mercurius\Memory\Domain\ContentScreen;
use Mercurius\Memory\Domain\Fact;
use Mercurius\Memory\Domain\Proposal;
use Mercurius\Memory\Domain\SleepEnricher;
use Mercurius\Memory\Domain\TextAnalyzer;
use Mercurius\Memory\Infrastructure\InMemoryAliasStore;
use Mercurius\Memory\Infrastructure\InMemoryMemoryStore;
use PHPUnit\Framework\TestCase;

/**
 * Nightly words for facts: kept beside the text and keyed with it, checked by code, in the fact's own script until
 * translations are on; a fact is found by a word that is not in its text, and a new rule of the analyzer keeps them.
 *
 * spec: brief/memory.md#ночное-обогащение
 */
final class EnrichmentTest extends TestCase {
    private InMemoryMemoryStore $store;
    private TextAnalyzer $analyzer;
    private bool $translate = false;

    protected function setUp(): void {
        $this->store = new InMemoryMemoryStore();
        $this->analyzer = new TextAnalyzer();
    }

    private function add(string $text): Fact {
        return $this->store->addFact('owner', Fact::KNOWLEDGE, $text, $this->analyzer->keys($text), 0.8, Fact::OWNER_SAID, null);
    }

    /** @param \Closure(Fact, bool): ?array{list<string>, list<list<string>>} $say */
    private function enrichment(\Closure $say, ?Aliases $aliases = null): Enrichment {
        return new Enrichment($this->store, $this->analyzer, new ContentScreen(static fn(string $t): bool => str_contains($t, 'sk-')), new class($say) implements SleepEnricher {
            public int $asked = 0;

            /** @param \Closure(Fact, bool): ?array{list<string>, list<list<string>>} $say */
            public function __construct(private readonly \Closure $say) {}

            public function enrich(array $facts, bool $translate): array {
                $this->asked += count($facts);
                return array_map(fn(Fact $f): ?array => ($this->say)($f, $translate), $facts);
            }

            public function spent(): array {
                return [1, 100, 0.0];
            }
        }, fn(): bool => $this->translate, $aliases);
    }

    /**
     * @param list<Fact> $facts
     * @return list<int>
     */
    private static function ids(array $facts): array {
        return array_map(static fn(Fact $f): int => $f->id, $facts);
    }

    public function testTheGuardKeepsOnlyWordsThatAddAKeyAndHoldNothingNew(): void {
        $fact = $this->add('Сервер pve-01 стоит в Хельсинки');
        $enrichment = $this->enrichment(static fn(): ?array => null);

        $kept = $enrichment->words($fact, ['гипервизор', 'Хельсинки', 'pve-02', 'sk-secret', 'hypervisor', 'финляндия', 'очень длинная фраза из многих слов', '7 узлов'], false);

        self::assertSame(['гипервизор', 'финляндия'], $kept, 'not a key it has, no new identifier or number, no secret, its own script, no phrase');
        self::assertContains('hypervisor', $enrichment->words($fact, ['hypervisor'], true), 'another language while translations are on');
    }

    public function testAFactIsFoundByAWordSleepAddedAndItsTextStays(): void {
        $fact = $this->add('Сервер pve-01 стоит в Хельсинки');
        $this->add('Владелец пьёт кофе без сахара');
        $recall = new Recall($this->store, $this->analyzer);
        self::assertNotContains($fact->id, self::ids($recall->find('где гипервизор', ['owner'])));

        [$facts, $words] = $this->enrichment(static fn(Fact $f): array => [['гипервизор', 'виртуализация'], []])->run();

        self::assertSame(2, $facts);
        self::assertGreaterThanOrEqual(2, $words);
        $now = $this->store->fact($fact->id);
        self::assertNotNull($now);
        self::assertSame([$fact->text, $fact->version, Fact::WORDS], [$now->text, $now->version, $now->enriched], 'the text and the version stay');
        self::assertSame(['гипервизор', 'виртуализация'], $now->words);
        self::assertContains($fact->id, self::ids($recall->find('где гипервизор', ['owner'])));

        $this->store->rekey($this->analyzer->keys(...));
        self::assertContains($fact->id, self::ids((new Recall($this->store, $this->analyzer))->find('где гипервизор', ['owner'])), 'keyed again with its words');
    }

    public function testTranslationsWaitForTheOwnerAndAChangedTextIsLookedAtAgain(): void {
        $fact = $this->add('Сервер pve-01 стоит в Хельсинки');
        $say = static fn(Fact $f, bool $translate): array => [$translate ? ['гипервизор', 'hypervisor'] : ['гипервизор'], []];
        $enrichment = $this->enrichment($say);

        $enrichment->run();
        self::assertSame([0, 0, []], $enrichment->run(), 'a fact is enriched once');
        self::assertSame(['гипервизор'], $this->store->fact($fact->id)?->words);

        $this->translate = true;
        $enrichment->run();
        $now = $this->store->facts[$fact->id];
        self::assertSame(Fact::TRANSLATED, $now->enriched);
        self::assertSame(['гипервизор', 'hypervisor'], $now->words, 'words given earlier stay');

        $this->store->changeFact($fact->id, 'Сервер pve-01 переехал в Таллин', $this->analyzer->keys('Сервер pve-01 переехал в Таллин'), 'переезд');
        self::assertSame(Fact::BARE, $this->store->fact($fact->id)?->enriched);
    }

    public function testAPairOfSpellingsJoinsTheTable(): void {
        $this->add('Прокс на сервере pve-01 обновлён');
        $aliases = new Aliases(new InMemoryAliasStore(), $this->analyzer, $this->store, new ContentScreen());

        [, , $lines] = $this->enrichment(static fn(): array => [[], [['прокс', 'proxmox'], ['кот', 'cat']]], $aliases)->run();

        self::assertCount(1, $lines, 'only a pair with one word of the text');
        self::assertStringContainsString('proxmox', $aliases->list());
    }

    public function testTheAgentsKeywordsStayWithTheFact(): void {
        $proposals = new Proposals($this->store, $this->analyzer, new ContentScreen(), 'k');

        $proposals->propose(Proposal::ADD, null, 'Кластер стоит в Хельсинки', 'сказал', ['owner'], null, null, keywords: ['proxmox']);

        $fact = $this->store->recent(['owner'], 1)[0] ?? null;
        self::assertSame(['proxmox'], $fact?->words);
    }
}

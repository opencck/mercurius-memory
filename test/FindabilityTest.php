<?php

declare(strict_types=1);

namespace Mercurius\Memory\Test;

use Mercurius\Memory\App\Consolidation;
use Mercurius\Memory\App\Enrichment;
use Mercurius\Memory\App\Findability;
use Mercurius\Memory\App\Proposals;
use Mercurius\Memory\App\Recall;
use Mercurius\Memory\Domain\ContentScreen;
use Mercurius\Memory\Domain\Fact;
use Mercurius\Memory\Domain\Proposal;
use Mercurius\Memory\Domain\SleepEnricher;
use Mercurius\Memory\Domain\SleepQuestions;
use Mercurius\Memory\Domain\TextAnalyzer;
use Mercurius\Memory\Infrastructure\InMemoryConsolidationJournal;
use Mercurius\Memory\Infrastructure\InMemoryMemoryStore;
use Mercurius\Memory\Infrastructure\InMemoryQuestionStore;
use PHPUnit\Framework\TestCase;

use function Mercurius\Memory\t;

/**
 * Self-check of search at night: the model of sleep writes questions to a fact without its words, the code asks them
 * before the words of the night and after; a fact not found goes first to get words, and one not found after two
 * nights with words is named.
 *
 * spec: brief/memory.md#самопроверка-поиска
 */
final class FindabilityTest extends TestCase {
    private InMemoryMemoryStore $store;
    private TextAnalyzer $analyzer;
    private InMemoryQuestionStore $questions;
    /** @var array<int, list<string>> questions the model writes, by the text of the fact */
    private array $asks = [];
    /** @var array<string, list<string>> words the model gives, by the text of the fact */
    private array $words = [];
    /** @var list<list<int>> the facts each call of enrichment got */
    public array $enrichedOrder = [];
    /** @var list<int> how many facts each call for questions got */
    public array $questionCalls = [];

    protected function setUp(): void {
        $this->store = new InMemoryMemoryStore();
        $this->analyzer = new TextAnalyzer();
        $this->questions = new InMemoryQuestionStore();
    }

    private function add(string $text): Fact {
        return $this->store->addFact('owner', Fact::KNOWLEDGE, $text, $this->analyzer->keys($text), 0.8, Fact::OWNER_SAID, null);
    }

    private function night(): string {
        $test = $this;
        $consolidation = new Consolidation($this->store, new Proposals($this->store, $this->analyzer, new ContentScreen(), 'k'), new InMemoryConsolidationJournal(), static fn(Proposal $p): bool => true, static fn(string $t): bool => true);
        $consolidation->useEnrichment(new Enrichment($this->store, $this->analyzer, new ContentScreen(), new class($test) implements SleepEnricher {
            public function __construct(private readonly FindabilityTest $test) {}

            public function enrich(array $facts, bool $translate): array {
                return $this->test->wordsFor($facts);
            }

            public function spent(): array {
                return [0, 0, 0.0];
            }
        }, static fn(): bool => false));
        $consolidation->useFindability(new Findability($this->store, new Recall($this->store, $this->analyzer), $this->questions, new class($test) implements SleepQuestions {
            public function __construct(private readonly FindabilityTest $test) {}

            public function questions(array $facts): array {
                return $this->test->questionsFor($facts);
            }

            public function spent(): array {
                return [1, 100, 0.001];
            }
        }));
        return $consolidation->run(true);
    }

    /**
     * @param list<Fact> $facts
     * @return list<?array{list<string>, list<list<string>>}>
     */
    public function wordsFor(array $facts): array {
        $this->enrichedOrder[] = array_map(static fn(Fact $f): int => $f->id, $facts);
        return array_map(fn(Fact $f): array => [$this->words[$f->text] ?? [], []], $facts);
    }

    /**
     * @param list<Fact> $facts
     * @return list<list<string>>
     */
    public function questionsFor(array $facts): array {
        $this->questionCalls[] = count($facts);
        return array_map(fn(Fact $f): array => $this->asks[$f->id] ?? [], $facts);
    }

    public function testTheNightSaysHowManyFactsItsQuestionsFindBeforeAndAfterTheWords(): void {
        $found = $this->add('Сервер pve-01 стоит в Хельсинки');
        $lost = $this->add('Жену владельца зовут Яна');
        $this->asks = [$found->id => ['где стоит сервер pve-01'], $lost->id => ['как имя супруги']];
        $this->words = ['Жену владельца зовут Яна' => ['супруга']];

        $report = $this->night();

        self::assertSame([2], $this->questionCalls);
        self::assertSame([$lost->id, $found->id], $this->enrichedOrder[0], 'not found goes first');
        self::assertStringContainsString(sprintf(t('app.consolidation.findability'), 2, 2, 1, 0.0, 0.0), $report);
        self::assertSame(['как имя супруги'], $this->questions->questions($lost->id, $lost->version));

        $this->night();
        self::assertCount(1, $this->questionCalls, 'questions are kept with the version of the fact');
    }

    public function testAFactNotFoundAfterTwoNightsWithWordsIsNamedOnce(): void {
        $lost = $this->add('Жену владельца зовут Яна');
        $this->asks = [$lost->id => ['как имя супруги']];
        $this->words = ['Жену владельца зовут Яна' => ['женщина']];

        $first = $this->night();
        self::assertStringNotContainsString(sprintf(t('app.consolidation.not_found'), '#' . $lost->id), $first);
        $second = $this->night();
        self::assertStringContainsString(sprintf(t('app.consolidation.not_found'), '#' . $lost->id), $second);
        $third = $this->night();
        self::assertStringNotContainsString(sprintf(t('app.consolidation.not_found'), '#' . $lost->id), $third, 'named once');
        self::assertContains('женщина', $this->store->fact($lost->id)->words ?? [], 'the words stay');
    }
}

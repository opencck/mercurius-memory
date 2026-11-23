<?php

declare(strict_types=1);

namespace Mercurius\Memory\Test;

use Mercurius\Memory\App\Consolidation;
use Mercurius\Memory\App\Enrichment;
use Mercurius\Memory\App\Proposals;
use Mercurius\Memory\Domain\ContentScreen;
use Mercurius\Memory\Domain\Fact;
use Mercurius\Memory\Domain\Proposal;
use Mercurius\Memory\Domain\SleepEnricher;
use Mercurius\Memory\Domain\SleepJudge;
use Mercurius\Memory\Domain\SleepNotes;
use Mercurius\Memory\Domain\TextAnalyzer;
use Mercurius\Memory\Domain\Verdict;
use Mercurius\Memory\Infrastructure\InMemoryConsolidationJournal;
use Mercurius\Memory\Infrastructure\InMemoryMemoryStore;
use PHPUnit\Framework\TestCase;

use function Mercurius\Memory\t;

/**
 * Sleep of a place: the model of sleep works on the facts of a project only while its sleep is on, and reads the talk
 * of the day there to write facts of that place. It never touches anything but facts.
 *
 * spec: brief/memory.md#сон-проекта
 */
final class PlaceSleepTest extends TestCase {
    private InMemoryMemoryStore $store;
    private TextAnalyzer $analyzer;
    /** @var list<string> */
    private array $judged = [];
    /** @var list<string> */
    private array $enriched = [];
    /** @var list<array{string, list<string>}> */
    private array $read = [];
    /** @var list<string> */
    private array $sleeping = [];
    /** @var array<string, string> */
    private array $talk = [];

    protected function setUp(): void {
        $this->store = new InMemoryMemoryStore();
        $this->analyzer = new TextAnalyzer();
    }

    private function add(string $scope, string $text): Fact {
        return $this->store->addFact($scope, Fact::KNOWLEDGE, $text, $this->analyzer->keys($text), 0.8, Fact::OWNER_SAID, null);
    }

    /** @param list<array{string, list<string>}> $notes what the model writes for a place */
    private function night(array $notes = []): string {
        $consolidation = new Consolidation($this->store, new Proposals($this->store, $this->analyzer, new ContentScreen(), 'k'), new InMemoryConsolidationJournal(), static fn(Proposal $p): bool => true, static fn(string $t): bool => true);
        $test = $this;
        $consolidation->useJudge(new class($test) implements SleepJudge {
            public function __construct(private readonly PlaceSleepTest $test) {}

            public function judge(array $pairs): array {
                foreach ($pairs as [$a]) {
                    $this->test->judgedScope($a->scope);
                }
                return array_fill(0, count($pairs), new Verdict(Verdict::UNRELATED, 0.9));
            }

            public function spent(): array {
                return [0, 0, 0.0];
            }
        });
        $consolidation->useEnrichment(new Enrichment($this->store, $this->analyzer, new ContentScreen(), new class($test) implements SleepEnricher {
            public function __construct(private readonly PlaceSleepTest $test) {}

            public function enrich(array $facts, bool $translate): array {
                foreach ($facts as $fact) {
                    $this->test->enrichedScope($fact->scope);
                }
                return array_fill(0, count($facts), null);
            }

            public function spent(): array {
                return [0, 0, 0.0];
            }
        }, static fn(): bool => false));
        $consolidation->usePlaces(new class($test, $notes) implements SleepNotes {
            /** @param list<array{string, list<string>}> $notes */
            public function __construct(private readonly PlaceSleepTest $test, private readonly array $notes) {}

            public function notes(string $talk, array $known): array {
                $this->test->readTalk($talk, array_map(static fn(Fact $f): string => $f->text, $known));
                return $this->notes;
            }

            public function spent(): array {
                return [1, 100, 0.001];
            }
        }, fn(string $scope): bool => !str_starts_with($scope, 'project:') || in_array($scope, $this->sleeping, true), fn(): array => $this->talk);
        return $consolidation->run(true);
    }

    public function judgedScope(string $scope): void {
        $this->judged[] = $scope;
    }

    public function enrichedScope(string $scope): void {
        $this->enriched[] = $scope;
    }

    /** @param list<string> $known */
    public function readTalk(string $talk, array $known): void {
        $this->read[] = [$talk, $known];
    }

    public function testTheModelLeavesTheFactsOfAProjectWhoseSleepIsOff(): void {
        $this->add('project:7', 'Сервер pve-01 стоит в Хельсинки, 128 ГБ памяти');
        $this->add('project:7', 'Сервер pve-01 стоит в Хельсинки, 64 ГБ памяти, это другой сервер');
        $this->add('owner', 'Владелец живёт в Паттайе');
        $this->add('owner', 'Владелец переехал в Таиланд и живёт в Паттайе');

        $this->night();

        self::assertNotContains('project:7', $this->judged, 'no pair of a sleeping-off project goes to the model');
        self::assertContains('owner', $this->judged);
        self::assertNotContains('project:7', $this->enriched);
        self::assertSame([], $this->read, 'no talk asked for');

        $this->sleeping = ['project:7'];
        [$this->judged, $this->enriched] = [[], []];
        $this->night();
        self::assertContains('project:7', $this->judged);
        self::assertContains('project:7', $this->enriched);
    }

    public function testSleepOfAProjectWritesFactsOfItsPlaceFromTheTalkOfTheDay(): void {
        $known = $this->add('project:7', 'Сервер проекта pve-01 стоит в Хельсинки');
        $this->sleeping = ['project:7'];
        $this->talk = ['project:7' => "owner: бэкапы складываем в /srv/backup\nagent: понял", 'project:9' => 'не спит'];

        $report = $this->night([['Бэкапы проекта лежат в /srv/backup', ['резервные копии']], ['  ', []]]);

        self::assertCount(1, $this->read, 'only the sleeping place');
        self::assertStringContainsString('/srv/backup', $this->read[0][0]);
        self::assertSame([$known->text], $this->read[0][1], 'what the place knows already goes with the talk');
        $facts = array_values(array_filter($this->store->active(10), static fn(Fact $f): bool => $f->text === 'Бэкапы проекта лежат в /srv/backup'));
        self::assertCount(1, $facts);
        self::assertSame('project:7', $facts[0]->scope);
        self::assertContains('резервные копии', $facts[0]->words);
        self::assertStringContainsString(sprintf(t('app.consolidation.noted'), $facts[0]->id, 'project:7', 'Бэкапы проекта лежат в /srv/backup'), $report);
    }

    public function testAPlaceWithoutTalkDoesNotWakeTheModel(): void {
        $this->sleeping = ['project:7'];
        $this->talk = ['project:7' => ''];

        $this->night([['Что-то', []]]);

        self::assertSame([], $this->read);
    }
}

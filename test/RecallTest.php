<?php

declare(strict_types=1);

namespace Mercurius\Memory\Test;

use Mercurius\Memory\App\Recall;
use Mercurius\Memory\Domain\Fact;
use Mercurius\Memory\Domain\TextAnalyzer;
use Mercurius\Memory\Infrastructure\InMemoryMemoryStore;
use PHPUnit\Framework\TestCase;

final class RecallTest extends TestCase {
    private InMemoryMemoryStore $store;
    private TextAnalyzer $analyzer;
    private Recall $recall;

    protected function setUp(): void {
        $this->store = new InMemoryMemoryStore();
        $this->analyzer = new TextAnalyzer();
        $this->recall = new Recall($this->store, $this->analyzer);
        foreach ([
            'Сервер pve-01 стоит в Хельсинки у Hetzner',
            'Владелец пьёт кофе без сахара',
            'Бэкапы базы делаются каждую ночь в 03:00',
            'Каждый день владелец гуляет с собакой',
            'Каждый день приходят отчёты о расходе',
        ] as $text) {
            $this->add('owner', $text);
        }
    }

    private function add(string $scope, string $text, float $trust = 0.8): Fact {
        return $this->store->addFact($scope, Fact::KNOWLEDGE, $text, $this->analyzer->keys($text), $trust, Fact::OWNER_SAID, null);
    }

    /**
     * @param list<Fact> $facts
     * @return list<string>
     */
    private static function texts(array $facts): array {
        return array_map(static fn(Fact $f): string => $f->text, $facts);
    }

    public function testANamedPersonBringsUpToTwoOfTheirFactsWithoutSharedWords(): void {
        // spec: BRIEF.md#факты — up to two facts of a name the message mentions, as holographic does
        $this->add('owner', 'Аня учится в пятом классе', 0.9);
        $this->add('owner', 'Аня ходит на плавание', 0.8);
        $this->add('owner', 'Аня любит рисовать', 0.7);

        $found = self::texts($this->recall->find('Как дела у Ани?', ['owner'], 7));

        self::assertSame(['Аня учится в пятом классе', 'Аня ходит на плавание'], $found, 'the two most trusted, though the name alone matches');
        self::assertSame([], self::texts($this->recall->find('как дела у ани?', ['owner'], 8)), 'not a name in lower case');
    }

    public function testASameRootWordHelpsAnotherWordFindAFactButNeverAlone(): void {
        // spec: BRIEF.md#анализ-текста — a root match counts 0.6 of an exact one and never recalls a fact alone
        $this->add('owner', 'Работает ночью в офисе');
        $this->add('owner', 'Офис на улице Ленина');

        self::assertSame(['Работает ночью в офисе'], self::texts($this->recall->find('ночные смены в офисе', ['owner'])));
        self::assertSame([], self::texts($this->recall->find('ночные смены', ['owner'])), 'the root alone recalls nothing');
    }

    public function testALoanwordFindsAFactWrittenTheOtherWay(): void {
        // spec: BRIEF.md#анализ-текста — a question with «впн» finds a fact that says «VPN»
        $this->add('owner', 'На ноутбуке настроен VPN WireGuard');

        self::assertSame(['На ноутбуке настроен VPN WireGuard'], self::texts($this->recall->find('Что с впн?', ['owner'])));
    }

    public function testRelatedFindsWhatIsTiedToAName(): void {
        $this->add('owner', 'Сервер pve-01 стоит в Хельсинки');
        $this->add('owner', 'На pve-01 диск NVMe на 2 ТБ');
        $this->add('owner', 'Владелец пьёт чай с бергамотом');
        $this->add('chat:telegram:-1', 'В группе тоже есть pve-01');

        $texts = self::texts($this->recall->related('pve-01', ['owner']));

        self::assertContains('На pve-01 диск NVMe на 2 ТБ', $texts);
        self::assertContains('Сервер pve-01 стоит в Хельсинки', $texts);
        self::assertNotContains('Владелец пьёт чай с бергамотом', $texts);
        self::assertNotContains('В группе тоже есть pve-01', $texts);
        self::assertSame(['Владелец пьёт чай с бергамотом'], self::texts($this->recall->related('бергамот', ['owner'])));
        self::assertSame([], $this->recall->related('', ['owner']));
    }

    public function testHyphenatedWordsAreAlsoTheirParts(): void {
        $this->add('owner', 'VPN-сервер на 3x-ui стоит в Амстердаме');

        self::assertSame(['VPN-сервер на 3x-ui стоит в Амстердаме'], self::texts($this->recall->find('где стоит VPN', ['owner'])));
    }

    public function testFindsTheFactTheQuestionIsAbout(): void {
        self::assertSame(['Владелец пьёт кофе без сахара'], self::texts($this->recall->find('Какой кофе я пью?', ['owner'])));
    }

    public function testIdentifierIsDecisive(): void {
        self::assertSame(['Сервер pve-01 стоит в Хельсинки у Hetzner'], self::texts($this->recall->find('что с pve-01', ['owner'])));
    }

    public function testChatterRecallsNothing(): void {
        self::assertSame([], $this->recall->find('ок, спасибо', ['owner']));
        self::assertSame([], $this->recall->find('', ['owner']));
    }

    public function testOneCommonWordOfALongerMessageIsNotEnough(): void {
        self::assertSame([], $this->recall->find('Какая погода будет на этой неделе каждый вечер в Москве?', ['owner']));
    }

    public function testOtherScopesAreNotSeen(): void {
        $this->add('principal:7', 'Гость любит зелёный чай');

        self::assertSame([], $this->recall->find('какой чай любит гость', ['owner']));
        self::assertSame(['Гость любит зелёный чай'], self::texts($this->recall->find('какой чай любит гость', ['principal:7'])));
    }

    public function testBudgetKeepsWholeLines(): void {
        for ($i = 0; $i < 30; $i++) {
            $this->add('chat:t:1', sprintf('Проект альфа: задача номер %d про миграцию базы и длинное описание, чтобы строка была подлиннее обычной', $i));
        }
        $found = $this->recall->find('проект альфа миграция базы', ['chat:t:1']);
        $block = Recall::format($found);

        self::assertNotSame([], $found);
        self::assertLessThanOrEqual(Recall::BUDGET, mb_strlen($block));
        foreach ($found as $fact) {
            self::assertStringContainsString($fact->text, $block);
        }
    }

    public function testFactIsNotRepeatedWithinTheWindow(): void {
        $first = $this->recall->find('Какой кофе я пью?', ['owner'], 42);
        $again = $this->recall->find('кофе с сахаром?', ['owner'], 42);
        $other = $this->recall->find('кофе с сахаром?', ['owner'], 43);

        self::assertCount(1, $first);
        self::assertSame([], $again);
        self::assertCount(1, $other);
    }

    public function testTrustOrdersEqualMatches(): void {
        $low = $this->add('chat:t:2', 'Пароль от роутера лежит в сейфе', 0.3);
        $high = $this->add('chat:t:2', 'Пароль от роутера меняли в мае', 0.9);

        $ids = array_map(static fn(Fact $f): int => $f->id, $this->recall->find('где пароль от роутера', ['chat:t:2']));

        self::assertSame([$high->id, $low->id], $ids);
    }

    public function testFormatMarksIdsAndIsEmptyForNothing(): void {
        self::assertSame('', Recall::format([]));
        $facts = $this->recall->find('pve-01', ['owner']);
        self::assertStringContainsString(sprintf('[#%d] ', $facts[0]->id), Recall::format($facts));
    }

    public function testABareNumberIsNotDecisive(): void {
        $this->add('owner', 'Порт 10 закрыт на роутере');

        self::assertSame([], $this->recall->find('напомни через 10 минут про звонок маме', ['owner']));
    }
}

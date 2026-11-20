<?php

declare(strict_types=1);

namespace Mercurius\Memory\Test;

use Mercurius\Memory\Domain\TextAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * Keys of a text: what search compares. Word forms meet, chatter has none, identifiers stay whole.
 */
final class TextAnalyzerTest extends TestCase {
    public function testWordFormsGiveOneKey(): void {
        $analyzer = new TextAnalyzer();

        self::assertSame($analyzer->keys('сервер'), $analyzer->keys('серверами'));
        self::assertSame($analyzer->keys('servers'), $analyzer->keys('server'));
        self::assertSame($analyzer->keys('ёлка'), $analyzer->keys('елки'));
    }

    public function testALoanwordMeetsItsOtherSpelling(): void {
        // spec: BRIEF.md#анализ-текста — «впн» and «VPN», «бэкап» and «backup» are one word
        $analyzer = new TextAnalyzer();

        self::assertContains('loan:vpn', $analyzer->keys('Настрой впн на сервере'));
        self::assertContains('loan:vpn', $analyzer->keys('VPN не работает'));
        self::assertContains('loan:server', $analyzer->keys('Настрой впн на сервере'), 'a Russian case form of a spelling');
        self::assertContains('loan:backup', $analyzer->keys('бекапы по ночам'), 'and its plural');
        self::assertNotContains('loan:token', $analyzer->keys('ток в сети'), 'by the form, never by the stem');
        self::assertContains('loan:telegram', $analyzer->keys('телеграм-бот не отвечает'), 'a part of a hyphenated word');
    }

    public function testAStemKnowsItsSameRootStems(): void {
        // spec: BRIEF.md#анализ-текста — same-root words from DerivBase.Ru
        $analyzer = new TextAnalyzer();

        self::assertContains('ноч', $analyzer->related('ночн'), '«ночные» and «ночь»');
        self::assertContains('ночн', $analyzer->related('ноч'), 'both ways');
        self::assertSame([], $analyzer->related('pve-01'), 'an identifier has none');
        self::assertSame([], $analyzer->related('vpn'), 'nor a word the dictionary does not know');
    }

    public function testChatterHasNoKeys(): void {
        self::assertSame([], (new TextAnalyzer())->keys('ну и что это же было бы, а?'));
        self::assertSame([], (new TextAnalyzer())->keys('ok, and so it is'));
    }

    public function testIdentifiersStayWhole(): void {
        $keys = (new TextAnalyzer())->keys('Перезапусти pve-01 на 10.0.0.1, лог в /var/log/app.log, ключ deploy_key');

        self::assertContains('pve-01', $keys);
        self::assertContains('10.0.0.1', $keys);
        self::assertContains('/var/log/app.log', $keys);
        self::assertContains('deploy_key', $keys);
    }

    public function testKeysAreUniqueAndOrdered(): void {
        $keys = (new TextAnalyzer())->keys('Сервер, сервер и ещё раз серверы');

        self::assertSame(array_values(array_unique($keys)), $keys);
        self::assertCount(1, array_filter($keys, static fn(string $k): bool => str_starts_with($k, 'сервер')));
    }

    public function testIdentifiersAreNamesNotNumbersOrHyphenatedWords(): void {
        foreach (['pve-01', 'gpt5', '10.0.0.1', 'snake_case', '/etc/hosts', 'v0.21.6'] as $key) {
            self::assertTrue(\Mercurius\Memory\Domain\TextAnalyzer::isIdentifier($key), $key);
        }
        foreach (['10', '2024', 'кто-то', 'e-mail', 'по-моему'] as $key) {
            self::assertFalse(\Mercurius\Memory\Domain\TextAnalyzer::isIdentifier($key), $key);
        }
    }
}

<?php

declare(strict_types=1);

namespace Mercurius\Memory\Test;

use InvalidArgumentException;
use Mercurius\Memory\App\CoreEntries;
use PHPUnit\Framework\TestCase;

final class CoreEntriesTest extends TestCase {
    private const PROFILE = "Живёт в Бангкоке\n§\nПишет по-русски\n§\nНе любит длинные ответы";

    public function testAnEntryIsAddedReplacedAndRemovedByItsText(): void {
        // spec: brief/memory.md#профиль-и-сводка — layer 1 is edited by entries, not rewritten whole
        self::assertSame(self::PROFILE . "\n§\nКот — Барсик", CoreEntries::apply(self::PROFILE, 'add', '', 'Кот — Барсик'));
        self::assertSame("Живёт в Паттайе\n§\nПишет по-русски\n§\nНе любит длинные ответы", CoreEntries::apply(self::PROFILE, 'replace', 'Бангкоке', 'Живёт в Паттайе'));
        self::assertSame("Живёт в Бангкоке\n§\nНе любит длинные ответы", CoreEntries::apply(self::PROFILE, 'remove', 'Пишет по-русски', ''));
        self::assertSame('Всё заново', CoreEntries::apply(self::PROFILE, 'all', '', 'Всё заново'));
        self::assertSame(self::PROFILE, CoreEntries::apply(self::PROFILE, 'add', '', 'Пишет по-русски'), 'a duplicate is not added');
    }

    public function testOldProseIsOneEntry(): void {
        self::assertSame("Свободный текст профиля.\n§\nНовое", CoreEntries::apply('Свободный текст профиля.', 'add', '', 'Новое'));
        self::assertSame(['Свободный текст профиля.'], CoreEntries::split('Свободный текст профиля.'));
        self::assertSame([], CoreEntries::split(''));
    }

    public function testAnExactEntryWinsOverOnesThatContainIt(): void {
        // ref: hermes _find_unique_match — a short entry stays addressable inside a longer one
        self::assertSame('тесты проходят', CoreEntries::apply("тест\n§\nтесты проходят", 'remove', 'тест', ''));
    }

    public function testAnAmbiguousOrMissingEntryIsRefusedWithTheClosest(): void {
        try {
            CoreEntries::apply("кот Барсик\n§\nкот Мурзик", 'remove', 'кот', '');
            self::fail('ambiguous was taken');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString(\Mercurius\Memory\t('app.core_entries.ambiguous'), $e->getMessage());
        }
        try {
            CoreEntries::apply(self::PROFILE, 'replace', 'живёт в Бангкоке давно', 'x');
            self::fail('missing was taken');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('Живёт в Бангкоке', $e->getMessage(), 'the closest entry is named');
        }
        $this->expectException(InvalidArgumentException::class);
        CoreEntries::apply(self::PROFILE, 'replace', '', 'x');
    }
}

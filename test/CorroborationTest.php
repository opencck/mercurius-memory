<?php

declare(strict_types=1);

namespace Mercurius\Memory\Test;

use DateTimeZone;
use Mercurius\Memory\App\Corroboration;
use Mercurius\Memory\Domain\TextAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * In how many different days a person said a fact in their own messages: code tells what holds from what was said once.
 *
 * spec: brief/memory.md#консолидация-dreaming — what the owner repeated on different days
 */
final class CorroborationTest extends TestCase {
    private const DAY = 86400;

    private static function moment(int $day, int $hour = 12): int {
        return 1_763_337_600 + $day * self::DAY + $hour * 3600; // 2025-11-17 00:00 UTC
    }

    public function testDaysAreCountedByTwoSharedWordsInTheOwnersOwnWords(): void {
        $days = (new Corroboration(new TextAnalyzer()))->days('Бэкапы базы делаются каждую ночь', [
            ['бэкапы базы ночью опять упали?', self::moment(0)],
            ['напомни про бэкап базы', self::moment(0, 18)],
            ['проверь бэкапы базы', self::moment(2)],
            ['бэкапы базы больше не делаются', self::moment(4)],
            ['какая у нас база?', self::moment(5)],
            ["посмотри\n\n<untrusted-data source=\"forward\">\nбэкапы базы делаются ночью\n</untrusted-data>", self::moment(6)],
        ], new DateTimeZone('UTC'));

        self::assertSame(2, $days, 'twice on the first day is one day; a negation, one word and a forward are not');
    }

    public function testALongRareWordIsEnoughAlone(): void {
        $days = (new Corroboration(new TextAnalyzer()))->days('Владелец продаёт через Wildberries', [
            ['что там на wildberries?', self::moment(1)],
            ['что там через неделю?', self::moment(2)],
        ], new DateTimeZone('UTC'));

        self::assertSame(1, $days);
    }

    public function testDaysAreTheOwnersLocalDays(): void {
        $corroboration = new Corroboration(new TextAnalyzer());
        $lines = [['кофе без сахара, как всегда', self::moment(0, 22)], ['кофе без сахара', self::moment(1, 1)]];

        self::assertSame(2, $corroboration->days('Владелец пьёт кофе без сахара', $lines, new DateTimeZone('UTC')));
        self::assertSame(1, $corroboration->days('Владелец пьёт кофе без сахара', $lines, new DateTimeZone('+03:00')), '01:00 and 04:00 of one day');
    }

    /**
     * spec: brief/memory.md#консолидация-dreaming — the message a person answers is not what they said
     * ref: references/hermes-dreaming/skills/dreaming/scripts/dream.py@46d2f9e:L1817-L1834 (_own_stems, _own_words) — the
     *      reply quote is left out of the person's own words; L1168-L1175 — a quote ends at its quote mark before "]",
     *      not at a "]" inside it
     */
    public function testTheMessageAnsweredIsNotThePersonsOwnWords(): void {
        $corroboration = new Corroboration(new TextAnalyzer());
        $quote = "[Это сообщение #9; оно отвечает на сообщение #8, которое написал ты (агент): «Бэкапы базы делаются каждую ночь [1]\nв 03:00, проверил»]\n";

        self::assertSame(0, $corroboration->days('Бэкапы базы делаются каждую ночь', [[$quote . 'ок', self::moment(0)]], new DateTimeZone('UTC')), 'a bare «ок» under the agent\'s words says nothing');
        self::assertSame(1, $corroboration->days('Бэкапы базы делаются каждую ночь', [[$quote . 'да, бэкапы базы ночью', self::moment(0)]], new DateTimeZone('UTC')), 'the words after the header are the person\'s');
        self::assertSame('да', Corroboration::ownWords("[This is message #9; it replies to message #8, written by you (the agent): «done»]\nда"));
        self::assertSame('[в скобках] бэкапы', Corroboration::ownWords('[в скобках] бэкапы'), 'a bracket of the person is their text');
    }

    public function testWordsAfterABlockOfOutsideDataAreStillThePersons(): void {
        $content = "<untrusted-data source=\"attachments\">\nбэкапы базы делаются ночью\n</untrusted-data>\n\nглянь, что с сервером";

        self::assertSame('глянь, что с сервером', Corroboration::ownWords($content));
    }
}

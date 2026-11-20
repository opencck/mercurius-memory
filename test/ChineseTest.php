<?php

declare(strict_types=1);

namespace Mercurius\Memory\Test;

use Mercurius\Memory\Domain\Chinese;
use Mercurius\Memory\Domain\TextAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * Chinese in search: either script finds the other, words of the dictionary and their pairs are keys, grammar is not,
 * numbers in characters meet digits, Latin next to characters is a word of its own.
 *
 * spec: brief/memory.md#китайский-язык
 */
final class ChineseTest extends TestCase {
    public function testTraditionalAndSimplifiedGiveTheSameKeys(): void {
        $analyzer = new TextAnalyzer();

        self::assertSame($analyzer->keys('公司邮件用的是什么'), $analyzer->keys('公司郵件用的是什麼'));
        self::assertSame('服务器', Chinese::fold('服務器'));
        self::assertSame('A1 2', Chinese::fold('Ａ１　２'), 'full-width letters and digits are plain');
    }

    public function testWordsOfTheDictionaryAndTheirPairsAreKeys(): void {
        $keys = (new TextAnalyzer())->keys('数据库每天凌晨备份');

        self::assertContains('数据库', $keys);
        self::assertContains('数据', $keys, 'a pair inside a word finds a question split another way');
        self::assertContains('备份', $keys);
        self::assertNotContains('库每', $keys, 'a pair across two longer words is not a key');
    }

    public function testASplitThatDiffersStillMeets(): void {
        $analyzer = new TextAnalyzer();
        $fact = $analyzer->keys('會計師陳小姐每季報稅');

        self::assertContains('报税', $fact, 'the fact splits 季报|税, the question says 报税');
        self::assertContains('报税', $analyzer->keys('会计师什么时候报税'));
    }

    public function testGrammarAndQuestionWordsAreNotKeys(): void {
        $analyzer = new TextAnalyzer();

        self::assertSame([], $analyzer->keys('我们的'));
        self::assertSame(['儿子'], $analyzer->keys('儿子几岁'), 'only what the question is about');
        self::assertContains('岁', $analyzer->keys('儿子八岁了'), 'a unit after a number is a key');
        self::assertNotContains('什么', $analyzer->keys('我用什么手机'));
    }

    public function testNumbersInCharactersAreDigits(): void {
        $analyzer = new TextAnalyzer();

        self::assertSame('3点', Chinese::numbers('三点'));
        self::assertSame('200美元', Chinese::numbers('两百美元'));
        self::assertSame('3月8日', Chinese::numbers('三月八日'));
        self::assertSame('7点40', Chinese::numbers('七点四十'));
        self::assertSame('一起', Chinese::numbers('一起'), 'a numeral inside a word stays');
        self::assertContains('3', $analyzer->keys('凌晨三点备份'));
        self::assertContains('3', $analyzer->keys('凌晨3点做什么'));
    }

    public function testLatinNextToCharactersIsAWordOfItsOwn(): void {
        $keys = (new TextAnalyzer())->keys('所有密码用keyring保存');

        self::assertContains('密码', $keys);
        self::assertContains('保存', $keys);
        self::assertSame($keys, array_values(array_unique($keys)));
        self::assertNotEmpty(array_intersect((new TextAnalyzer())->keys('keyring'), $keys));
    }

    public function testANegationIsSeen(): void {
        $analyzer = new TextAnalyzer();

        self::assertTrue(TextAnalyzer::negates('林芳喝绿茶，不加糖'));
        self::assertContains('加糖', $analyzer->negated('林芳喝绿茶，不加糖'));
        self::assertFalse(TextAnalyzer::negates('林芳喝绿茶'));
    }

    public function testATwoCharacterNameTheDictionaryLacksIsAPair(): void {
        self::assertContains('林芳', (new TextAnalyzer())->keys('主人的妻子叫林芳'));
    }
}

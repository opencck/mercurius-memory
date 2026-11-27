<?php

declare(strict_types=1);

namespace Mercurius\Memory\Domain;

/**
 * Chinese text for search: Traditional characters folded to Simplified, numbers in characters turned into digits,
 * runs of characters split into words by jieba's dictionary and read as character pairs too. The text of a fact is
 * stored as written; only its keys are folded.
 *
 * spec: brief/memory.md#китайский-язык
 * ref: references/hermes-plugin-holographic/text.py@9b49943:L2042-L2140 (_ZhTable, _zh_table, zh_words) — the
 *      dictionary's format and the most probable split by word frequency (jieba's route without its HMM)
 * ref: references/hermes-plugin-holographic/text.py@9b49943:L2142-L2161 (_zh_alone) — a one-character word is a key
 *      only when it is not a function character, a negator, a numeral or one of the commonest; holographic searches
 *      substrings of the text with FTS, here pairs and words are keys of the fact
 * ref: references/hermes-plugin-holographic/text.py@9b49943:L1906, L1918 (CJK_FUNCTION, CJK_NEGATORS) — the lists
 */
final class Chinese {
    /** characters that carry grammar, not the subject */
    public const FUNCTION = '的是了吗呢吧啊呀哦嗯么怎什哪谁几多少在有和与及或就都也还要会能可以把被给让对为从到这那你我他她它们个一不没很太该应些嘛';
    public const NEGATORS = '不没无别非未';
    /** words of a question that ask, not tell */
    public const QUESTION_WORDS = ['什么', '怎么', '怎样', '为什么', '哪里', '哪儿', '哪个', '多少', '多久', '几点', '时候', '什么时候', '是不是', '有没有', '可以', '一下', '现在', '告诉',
        '几月', '几号', '几岁', '几个', '多长', '多大', '多远', '多高', '哪天', '哪些', '怎么样', '如何', '请问'];
    /** numerals and calendar units: never a key alone */
    private const NOT_ALONE = '零〇一二三四五六七八九十百千万亿两半几年月日号天周点分秒';
    /** from this round(4 · ln f) a one-character word is too common to be a key alone */
    private const COMMON = 43;
    private const DIGITS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    private const WORDS = __DIR__ . '/../../resources/dictionaries/zh_words.txt.gz';
    private const FOLD = __DIR__ . '/../../resources/dictionaries/zh_fold.txt';
    /** units after which characters of a number are that number: 三点, 十五岁, 两百美元 */
    private const UNITS = ['个', '元', '块', '岁', '度', '年', '月', '日', '号', '天', '周', '点', '分', '秒', '次', '人', '位', '层', '楼', '倍',
        '公里', '公斤', '分钟', '小时', '星期', '美元', '欧元', '年级', '兆', '岁', '号', '本', '张', '台', '辆', '只', '件', '杯', '瓶', '万', '亿'];

    /** @var array<array-key, string>|null a character (a digit one comes back as an int key) => its folded form */
    private static ?array $fold = null;
    /** @var array<string, true>|null */
    private static ?array $grammar = null;
    /** @var array<string, true>|null */
    private static ?array $numerals = null;
    /** @var array{array<string, string>, float, array<string, true>}|null the lines by first character, floor, names */
    private static ?array $table = null;

    public static function hasHan(string $text): bool {
        return preg_match('~\p{Han}~u', $text) === 1;
    }

    /** Traditional characters as Simplified, full-width Latin letters and digits as ASCII. */
    public static function fold(string $text): string {
        if (self::$fold === null) {
            self::$fold = [];
            $lines = is_file(self::FOLD) ? file(self::FOLD, FILE_IGNORE_NEW_LINES) : false;
            $data = $lines === false ? [] : array_values(array_filter($lines, static fn(string $l): bool => $l !== '' && $l[0] !== '#'));
            if (count($data) === 2) {
                $from = mb_str_split($data[0]);
                $to = mb_str_split($data[1]);
                if (count($from) === count($to)) {
                    self::$fold = array_combine($from, $to);
                }
            }
            for ($c = 0xFF01; $c <= 0xFF5E; $c++) {
                self::$fold[mb_chr($c)] = chr($c - 0xFEE0);
            }
            self::$fold["\u{3000}"] = ' ';
        }
        return strtr($text, self::$fold);
    }

    /**
     * Characters of a number before a unit as digits: «三点» — «3点», «三月八日» — «3月8日», «两百美元» — «200美元». A
     * numeral inside a word stays (一起, 统一).
     */
    public static function numbers(string $text): string {
        $units = implode('|', array_map(static fn(string $u): string => preg_quote($u, '~'), self::UNITS));
        return (string) preg_replace_callback(
            '~(?<![零〇一二三四五六七八九十百千万两])([零〇一二三四五六七八九十百千万两]+)(?=' . $units . ')|(?<=点)([零〇一二三四五六七八九十]+)(?![零〇一二三四五六七八九十])~u',
            static function (array $m): string {
                $numeral = ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '');
                $value = self::value($numeral);
                return $value === null ? $m[0] : (string) $value;
            },
            $text,
        );
    }

    /** The value of a numeral in characters; null — not one. */
    private static function value(string $numeral): ?int {
        $digits = ['零' => 0, '〇' => 0, '一' => 1, '二' => 2, '两' => 2, '三' => 3, '四' => 4, '五' => 5, '六' => 6, '七' => 7, '八' => 8, '九' => 9];
        $powers = ['十' => 10, '百' => 100, '千' => 1000];
        $total = 0;
        $section = 0;
        $digit = null;
        foreach (mb_str_split($numeral) as $c) {
            if (isset($digits[$c])) {
                $digit = $digit === null ? $digits[$c] : $digit * 10 + $digits[$c];
            } elseif (isset($powers[$c])) {
                $section += ($digit ?? 1) * $powers[$c];
                $digit = null;
            } elseif ($c === '万') {
                $total += ($section + ($digit ?? 0)) * 10000;
                $section = 0;
                $digit = null;
            } else {
                return null;
            }
        }
        return $total + $section + ($digit ?? 0);
    }

    /**
     * The keys of a run of Chinese characters (folded), as words of search: each word of the dictionary with the pairs
     * of its characters; a one-character word alone when it means something, with the pair it makes across the edge
     * of its neighbour (税 in 每季报税 is also 报税: the split of a fact and of a question may differ); two meaningful
     * one-character words side by side as a pair (a name the dictionary lacks: 林芳). Grammar is not a key.
     *
     * @return list<non-empty-list<string>>
     */
    public static function terms(string $run): array {
        $words = self::segment($run);
        $count = count($words);
        /** @var array<int, list<string>> $terms by the position of the word */
        $terms = [];
        foreach ($words as $i => $word) {
            if (in_array($word, self::QUESTION_WORDS, true)) {
                continue;
            }
            $chars = mb_str_split($word);
            $content = array_values(array_filter($chars, static fn(string $c): bool => !self::grammar($c)));
            if ($content === []) {
                continue;
            }
            if (count($chars) >= 2) {
                $term = [$word, ...self::pairs($word)];
                // a word with grammar in it (几岁, 不加糖) is also what it is about
                if (count($content) < count($chars)) {
                    $rest = implode('', $content);
                    if (mb_strlen($rest) >= 2 || self::alone($rest)) {
                        $term[] = $rest;
                    }
                }
                $terms[$i] = array_values(array_unique($term));
            } elseif (self::alone($word)) {
                $terms[$i] = [$word];
            }
        }
        for ($i = 0; $i + 1 < $count; $i++) {
            [$a, $b] = [$words[$i], $words[$i + 1]];
            $pair = mb_substr($a, -1) . mb_substr($b, 0, 1);
            if (self::grammar(mb_substr($pair, 0, 1)) || self::grammar(mb_substr($pair, 1, 1)) || in_array($pair, self::QUESTION_WORDS, true)) {
                continue;
            }
            if (mb_strlen($a) === 1 && mb_strlen($b) === 1) {
                if (isset($terms[$i]) || isset($terms[$i + 1])) {
                    $terms[$i . '.'] = [$pair];
                }
            } elseif (mb_strlen($b) === 1 && isset($terms[$i + 1])) {
                $terms[$i + 1][] = $pair;
            } elseif (mb_strlen($a) === 1 && isset($terms[$i])) {
                $terms[$i][] = $pair;
            }
        }
        ksort($terms, SORT_NATURAL);
        return array_values(array_map(static fn(array $t): array => array_values(array_unique($t)), array_filter($terms, static fn(array $t): bool => $t !== [])));
    }

    /** @return list<string> the pairs of adjacent characters that are not grammar on both sides */
    private static function pairs(string $text): array {
        $chars = mb_str_split($text);
        if (count($chars) === 1) {
            return [];
        }
        $pairs = [];
        for ($i = 0; $i + 1 < count($chars); $i++) {
            $a = $chars[$i];
            $b = $chars[$i + 1];
            if (self::grammar($a) || self::grammar($b)) {
                continue;
            }
            $pairs[] = $a . $b;
        }
        return array_values(array_filter(array_unique($pairs), static fn(string $p): bool => !in_array($p, self::QUESTION_WORDS, true)));
    }

    private static function grammar(string $char): bool {
        self::$grammar ??= array_fill_keys(mb_str_split(self::FUNCTION . self::NEGATORS), true);
        return isset(self::$grammar[$char]);
    }

    /** Whether a one-character word is a key alone: 猫, 车, 药 — not 的, 不, 三, 年, nor the commonest, nor a surname. */
    private static function alone(string $char): bool {
        self::$numerals ??= array_fill_keys(mb_str_split(self::NOT_ALONE), true);
        if (self::grammar($char) || isset(self::$numerals[$char])) {
            return false;
        }
        [, $floor, $names] = self::table();
        if (isset($names[$char])) {
            return false;
        }
        $p = self::wordsOf($char)[$char] ?? null;
        // the log probability over the floor (minus the log of the total) is round(4 · ln f) / 4
        return $p !== null && $p - $floor < ((float) self::COMMON - 0.5) / 4.0;
    }

    /**
     * A run of folded characters split into the words most probable together; a character that begins no word of the
     * dictionary stands alone. Without the dictionary — characters.
     *
     * @return list<string>
     */
    public static function segment(string $run): array {
        [$lines, $floor] = self::table();
        $chars = mb_str_split($run);
        $n = count($chars);
        if ($lines === [] || $n === 0) {
            return $chars;
        }
        $score = array_fill(0, $n + 1, 0.0);
        $end = range(1, $n + 1);
        for ($i = $n - 1; $i >= 0; $i--) {
            $words = self::wordsOf($chars[$i]);
            $alone = $words[$chars[$i]] ?? null;
            $best = $alone === null ? null : $alone + $score[$i + 1];
            $word = $chars[$i];
            for ($size = 2; $i + $size <= $n; $size++) {
                $word .= $chars[$i + $size - 1];
                $p = $words[$word] ?? null;
                if ($p !== null && ($best === null || $p + $score[$i + $size] >= $best)) {
                    $best = $p + $score[$i + $size];
                    $end[$i] = $i + $size;
                }
                if ($size >= self::$longest[$chars[$i]]) {
                    break;
                }
            }
            $score[$i] = $best ?? $floor + $score[$i + 1];
        }
        $out = [];
        for ($i = 0; $i < $n; $i = $end[$i]) {
            $out[] = implode('', array_slice($chars, $i, $end[$i] - $i));
        }
        return $out;
    }

    /** @var array<string, array<string, float>> the words of a first character, parsed when a text first needs them */
    private static array $parsed = [];
    /** @var array<string, int> the length of the longest word a character begins, by character, as parsed */
    private static array $longest = [];
    /** the natural log of the total of the frequencies */
    private static float $total = 0.0;

    /**
     * The words that begin with a character, with their log probability: the dictionary keeps one line per first
     * character, and only the lines of characters met in text are read into words.
     *
     * @return array<string, float>
     */
    private static function wordsOf(string $first): array {
        if (isset(self::$parsed[$first])) {
            return self::$parsed[$first];
        }
        $line = self::table()[0][$first] ?? null;
        $words = [];
        $max = 1;
        if ($line !== null) {
            foreach (explode(' ', $line) as $rest) {
                // the frequency character is round(4 · ln f) in 0-9A-Za-z
                $step = $rest === '' ? false : strpos(self::DIGITS, $rest[-1]);
                if ($step !== false) {
                    $word = $first . substr($rest, 0, -1);
                    $words[$word] = (float) $step / 4.0 - self::$total;
                    $max = max($max, mb_strlen($word));
                }
            }
        }
        self::$longest[$first] = $max;
        return self::$parsed[$first] = $words;
    }

    /**
     * jieba's dictionary, read once per process on the first Chinese text: the line of words of each first character
     * (parsed when needed), the floor for a character that begins no word, the characters tagged as a name. A missing
     * or damaged file leaves Chinese to single characters and their pairs.
     *
     * @return array{array<string, string>, float, array<string, true>}
     */
    private static function table(): array {
        if (self::$table !== null) {
            return self::$table;
        }
        $packed = is_file(self::WORDS) ? file_get_contents(self::WORDS) : false;
        $text = $packed === false ? false : @gzdecode($packed);
        if ($text === false) {
            return self::$table = [[], 0.0, []];
        }
        $lines = [];
        $total = 0.0;
        $names = [];
        foreach (explode("\n", $text) as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if ($line[0] === '=') {
                $total = (float) substr($line, 1);
                self::$total = $total;
            } elseif ($line[0] === '!') {
                foreach (mb_str_split(substr($line, 1)) as $c) {
                    $names[$c] = true;
                }
            } else {
                $space = strpos($line, ' ');
                if ($space !== false) {
                    // the first character, then " <rest><frequency>" per word; a lone character has an empty rest
                    $lines[substr($line, 0, $space)] = substr($line, $space + 1);
                }
            }
        }
        return self::$table = [$lines, -$total, $names];
    }
}

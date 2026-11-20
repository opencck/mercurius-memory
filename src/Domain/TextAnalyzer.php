<?php

declare(strict_types=1);

namespace Mercurius\Memory\Domain;

use Wamania\Snowball\Stemmer\Stemmer;
use Wamania\Snowball\StemmerFactory;

/**
 * Keys of a text for search: stems of meaningful words (Russian and English Snowball), identifiers whole. The quality
 * of recall rests on this more than on vectors.
 *
 * spec: brief/memory.md#анализ-текста
 * ref: references/hermes-plugin-holographic/retrieval.py@4a794f8:L380 (_coverage_rank) — keys are word stems and
 *      identifiers; stop words carry no information
 */
final class TextAnalyzer {
    /** Words that say nothing about the subject. */
    private const STOP = [
        'и', 'в', 'во', 'не', 'что', 'он', 'на', 'я', 'с', 'со', 'как', 'а', 'то', 'все', 'она', 'так', 'его', 'но', 'да',
        'ты', 'к', 'у', 'же', 'вы', 'за', 'бы', 'по', 'только', 'ее', 'мне', 'было', 'вот', 'от', 'меня', 'еще', 'нет',
        'о', 'из', 'ему', 'теперь', 'когда', 'даже', 'ну', 'вдруг', 'ли', 'если', 'уже', 'или', 'ни', 'быть', 'был', 'него',
        'до', 'вас', 'нибудь', 'опять', 'уж', 'вам', 'ведь', 'там', 'потом', 'себя', 'ничего', 'ей', 'может', 'они', 'тут',
        'где', 'есть', 'надо', 'ней', 'для', 'мы', 'тебя', 'их', 'чем', 'была', 'сам', 'чтоб', 'без', 'будто', 'чего', 'раз',
        'тоже', 'себе', 'под', 'будет', 'ж', 'тогда', 'кто', 'этот', 'того', 'потому', 'этого', 'какой', 'совсем', 'ним',
        'здесь', 'этом', 'один', 'почти', 'мой', 'тем', 'чтобы', 'нее', 'сейчас', 'были', 'куда', 'зачем', 'всех', 'никогда',
        'можно', 'при', 'наконец', 'два', 'об', 'другой', 'хоть', 'после', 'над', 'больше', 'тот', 'через', 'эти', 'нас',
        'про', 'всего', 'них', 'какая', 'много', 'разве', 'три', 'эту', 'моя', 'впрочем', 'хорошо', 'свою', 'этой', 'перед',
        'иногда', 'лучше', 'чуть', 'том', 'нельзя', 'такой', 'им', 'более', 'всегда', 'конечно', 'всю', 'между', 'это',
        'еще', 'ещё', 'пожалуйста', 'спасибо', 'привет', 'ок', 'окей', 'ага', 'угу',
        'the', 'a', 'an', 'and', 'or', 'but', 'is', 'are', 'was', 'were', 'be', 'been', 'it', 'its', 'this', 'that', 'to',
        'of', 'in', 'on', 'at', 'for', 'with', 'as', 'by', 'from', 'so', 'do', 'does', 'did', 'not', 'no', 'yes', 'ok',
        'i', 'you', 'he', 'she', 'we', 'they', 'me', 'my', 'your', 'our', 'their', 'what', 'which', 'who', 'please', 'thanks',
    ];

    /**
     * A borrowed word written either way, Latin or Cyrillic: each group is one word. A word belongs to a group by its
     * exact form — the spelling, an English plural, a Russian case ending — never by its stem: Snowball cuts «токен» to
     * «ток» and «виза» to «виз».
     *
     * spec: brief/memory.md#анализ-текста — loanwords
     * ref: references/hermes-plugin-holographic/text.py@4a794f8:L670-L719 (_LOANWORDS, _RU_ENDINGS, _loan_forms) — the
     *      table and the forms; holographic also keeps search prefixes for FTS5, here the group's key is enough
     */
    private const LOANWORDS = 'telegram телеграм телеграмм | whatsapp вотсап вотсапп ватсап ватсапп вацап | youtube ютуб'
        . ' | instagram инстаграм инстаграмм | facebook фейсбук | google гугл | gmail гмейл | iphone айфон | ipad айпад'
        . ' | android андроид | windows виндовс | linux линукс | macbook макбук | backup бэкап бекап | server сервер | vpn впн'
        . ' | wifi вайфай | bluetooth блютуз | email имейл емейл эмейл | zoom зум | skype скайп | excel эксель | notion ношн'
        . ' | github гитхаб | docker докер | python питон | deploy деплой | commit коммит | token токен | prompt промпт'
        . ' | chatgpt чатгпт | dashboard дашборд | bitcoin биткоин биткойн | hotel отель | condo кондо | visa виза'
        . ' | netflix нетфликс | spotify спотифай | tiktok тикток | twitter твиттер | samsung самсунг | xiaomi сяоми'
        . ' | booking букинг | laptop лэптоп ноутбук | smartphone смартфон | router роутер | chat чат | online онлайн | site сайт'
        . ' | bot бот | marketplace маркетплейс | startup стартап | meetup митап | coworking коворкинг | freelance фриланс';
    private const RU_ENDINGS = ['а', 'я', 'у', 'ю', 'е', 'и', 'ы', 'ой', 'ей', 'ою', 'ею', 'ом', 'ем', 'ам', 'ям', 'ами', 'ями', 'ах', 'ях', 'ов', 'ев'];

    /** @var array<string, string>|null form => the key of its group */
    private static ?array $loans = null;

    /** Russian stems of one root, both ways: the dictionary and its licence lie beside it. */
    private const DERIVATION = __DIR__ . '/../../resources/dictionaries/ru_derivation.tsv.gz';

    /** @var array<string, list<string>>|null stem => stems linked to it by a derivation */
    private static ?array $derivation = null;

    /**
     * Words that negate the word after them (a modal passes it on: «не может прийти»).
     *
     * ref: references/hermes-dreaming/skills/dreaming/scripts/dream_lang.py@21878d3:L175-L184 (NEGATION_WORDS,
     *      _MODALS) — the Russian and English words of the lists
     */
    public const NEGATIONS = ['не', 'нет', 'без', 'ни', 'никогда', 'нельзя', 'not', 'no', 'never', 'nor', 'neither', 'none',
        'nothing', 'nobody', 'without', 'cannot', 'doesn', 'don', 'didn', 'isn', 'aren', 'wasn', 'weren', 'hasn', 'haven',
        'hadn', 'shouldn', 'wouldn', 'couldn'];
    private const MODALS = ['должен', 'должна', 'должно', 'должны', 'может', 'могут', 'мог', 'могла', 'могли', 'нужно', 'надо',
        'будет', 'будут', 'стал', 'стала', 'стали', 'хочет', 'хотят', 'умеет', 'can', 'could', 'should', 'would', 'will',
        'must', 'may', 'might', 'need', 'needs', 'have', 'has', 'had', 'do', 'does', 'did', 'be', 'been', 'is', 'are',
        'was', 'were', 'want', 'wants', 'going'];

    /** @var array<string, true> */
    private readonly array $stop;
    private ?Stemmer $russian = null;
    private ?Stemmer $english = null;

    public function __construct() {
        $this->stop = array_fill_keys(self::STOP, true);
    }

    /** @return list<string> unique keys in the order they appear */
    public function keys(string $text): array {
        $keys = [];
        foreach ($this->terms($text) as $term) {
            foreach ($term as $key) {
                $keys[$key] = true;
            }
        }
        // PHP turns a numeric string key ("10") into an int: keys stay strings.
        return array_map(strval(...), array_keys($keys));
    }

    /**
     * The words of a text, each as the keys it may match by: its stem (or the identifier whole) and the key of its
     * borrowed-word group. A word counts once in coverage, however many keys it has; a hyphenated word is the whole and
     * each part as words of their own.
     *
     * spec: brief/memory.md#анализ-текста — a word is counted once: «бэкап» and its group key are one word
     *
     * @return list<non-empty-list<string>> in the order they appear; a word met again is not repeated
     */
    public function terms(string $text): array {
        $text = str_replace(['ё', 'Ё'], ['е', 'Е'], $text);
        preg_match_all('~/[\w.\-/]+|[\p{L}\p{N}_]+(?:[.\-][\p{L}\p{N}_]+)*~u', $text, $m);
        /** @var list<non-empty-list<string>> $terms */
        $terms = [];
        $seen = [];
        foreach ($m[0] as $token) {
            $word = mb_strtolower($token);
            if (isset($this->stop[$word]) || mb_strlen($word) < 2) {
                continue;
            }
            $term = [];
            $key = self::isIdentifier($word) || preg_match('~\d~', $word) === 1 ? $word : $this->stem($word);
            if ($key !== '' && !isset($this->stop[$key])) {
                $term[] = $key;
            }
            $loan = self::loans()[$word] ?? null;
            if ($loan !== null) {
                $term[] = $loan;
            }
            self::addTerm($terms, $seen, $term);
            // A hyphenated word ("vpn-сервер", "telegram-бот") is also its parts: the question says "VPN" or "бот".
            if (str_contains($word, '-') && !self::isIdentifier($word)) {
                foreach (explode('-', $word) as $part) {
                    $part_ = [];
                    $stem = mb_strlen($part) < 2 || isset($this->stop[$part]) ? '' : $this->stem($part);
                    if ($stem !== '' && !isset($this->stop[$stem])) {
                        $part_[] = $stem;
                    }
                    $loan = self::loans()[$part] ?? null;
                    if ($loan !== null) {
                        $part_[] = $loan;
                    }
                    self::addTerm($terms, $seen, $part_);
                }
            }
        }
        return $terms;
    }

    /**
     * The keys of the words a text negates: the word after a negation, past a modal («не может прийти» — «приш»).
     *
     * spec: brief/memory.md#консолидация-dreaming — a negation turns a fact into another one
     * ref: references/hermes-dreaming/skills/dreaming/scripts/dream.py@21878d3:L2326-L2348 (_negated) — the word after
     *      the negation; the two-way words and Chinese are not taken
     *
     * @return list<string>
     */
    public function negated(string $text): array {
        preg_match_all('~\p{L}+~u', mb_strtolower(str_replace(['ё', 'Ё'], ['е', 'Е'], $text)), $m);
        $words = $m[0];
        $keys = [];
        foreach ($words as $i => $word) {
            if (!in_array($word, self::NEGATIONS, true)) {
                continue;
            }
            $next = $i + 1;
            while (isset($words[$next]) && in_array($words[$next], self::MODALS, true)) {
                $next++;
            }
            if (isset($words[$next]) && !in_array($words[$next], self::NEGATIONS, true)) {
                foreach ($this->keys($words[$next]) as $key) {
                    $keys[$key] = true;
                }
            }
        }
        return array_map(strval(...), array_keys($keys));
    }

    /** Whether a text has a negation at all. */
    public static function negates(string $text): bool {
        preg_match_all('~\p{L}+~u', mb_strtolower(str_replace(['ё', 'Ё'], ['е', 'Е'], $text)), $m);
        return array_intersect($m[0], self::NEGATIONS) !== [];
    }

    /**
     * A key kept whole and decisive in recall: names a host, a version, an address or a path. Letters with digits
     * (pve-01, gpt5), or parts joined by a dot, slash or underscore (10.0.0.1, snake_case, /etc/hosts). A bare number
     * ("10 минут") or a hyphenated word ("кто-то", "e-mail") is an ordinary word.
     */
    public static function isIdentifier(string $key): bool {
        if (preg_match('~^/|[\w][._/][\w]~u', $key) === 1) {
            return true;
        }
        return preg_match('~\p{L}~u', $key) === 1 && preg_match('~\d~', $key) === 1;
    }

    /**
     * Stems of one root with a key: Snowball makes «ночь» «ноч» but «ночные» «ночн», and the dictionary links them.
     * Recall counts a match through one at a part of an exact match and never by it alone.
     *
     * spec: brief/memory.md#анализ-текста — same-root words
     * ref: references/hermes-plugin-holographic/text.py@4a794f8:L832-L912 (word_roots, _related) — the DerivBase.Ru
     *      pairs as an adjacency map loaded on the first Russian word; English (Open English Wordnet) is not taken
     *
     * @return list<string>
     */
    public function related(string $key): array {
        if (self::isIdentifier($key) || preg_match('~\p{Cyrillic}~u', $key) !== 1) {
            return [];
        }
        return self::derivation()[$key] ?? [];
    }

    /** @return array<string, list<string>> */
    private static function derivation(): array {
        if (self::$derivation !== null) {
            return self::$derivation;
        }
        $pairs = [];
        // read once per process, about 270 KB packed; a missing or damaged file leaves words without roots
        $packed = is_file(self::DERIVATION) ? file_get_contents(self::DERIVATION) : false;
        $text = $packed === false ? false : @gzdecode($packed);
        if ($text === false) {
            return self::$derivation = [];
        }
        foreach (explode("\n", $text) as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $pair = explode("\t", rtrim($line, "\r"), 3);
            if (count($pair) !== 2 || $pair[0] === '' || $pair[1] === '') {
                continue;
            }
            $pairs[$pair[0]][] = $pair[1];
            $pairs[$pair[1]][] = $pair[0];
        }
        return self::$derivation = $pairs;
    }

    /**
     * @param list<non-empty-list<string>> $terms
     * @param array<string, true> $seen
     * @param list<string> $term
     * @param-out list<non-empty-list<string>> $terms
     * @param-out array<string, true> $seen
     */
    private static function addTerm(array &$terms, array &$seen, array $term): void {
        $term = array_values(array_unique($term));
        $id = implode("\x1f", $term);
        if ($term !== [] && !isset($seen[$id])) {
            $seen[$id] = true;
            $terms[] = $term;
        }
    }

    /** @return array<string, string> every form of a borrowed word => "loan:<its first spelling>" */
    private static function loans(): array {
        if (self::$loans !== null) {
            return self::$loans;
        }
        $table = [];
        foreach (explode('|', self::LOANWORDS) as $group) {
            $spellings = preg_split('/\s+/', trim($group), -1, PREG_SPLIT_NO_EMPTY);
            if ($spellings === false || $spellings === []) {
                continue;
            }
            $key = 'loan:' . $spellings[0];
            foreach ($spellings as $spelling) {
                foreach (self::forms($spelling) as $form) {
                    $table[$form] = $key;
                }
            }
        }
        return self::$loans = $table;
    }

    /** @return list<string> the forms that make a word a spelling of its group */
    private static function forms(string $spelling): array {
        if (preg_match('~\p{Cyrillic}~u', $spelling) !== 1) {
            return [$spelling, $spelling . (preg_match('/(s|x|z|ch|sh)$/', $spelling) === 1 ? 'es' : 's')];
        }
        $last = mb_substr($spelling, -1);
        // a word Russian does not decline ("кондо")
        if (in_array($last, ['о', 'и', 'у', 'э', 'ю'], true)) {
            return [$spelling];
        }
        $base = in_array($last, ['а', 'я', 'ь', 'й'], true) ? mb_substr($spelling, 0, -1) : $spelling;
        return [$spelling, ...array_map(static fn(string $e): string => $base . $e, self::RU_ENDINGS)];
    }

    private function stem(string $word): string {
        if (preg_match('~\p{Cyrillic}~u', $word) === 1) {
            $this->russian ??= StemmerFactory::create('ru');
            return $this->russian->stem($word);
        }
        $this->english ??= StemmerFactory::create('en');
        return $this->english->stem($word);
    }
}

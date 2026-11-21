<?php

declare(strict_types=1);

namespace Mercurius\Memory\App;

use Mercurius\Memory\Domain\ContentScreen;
use Mercurius\Memory\Domain\Fact;

/**
 * The code's check of a text the model of sleep wrote for two facts: every number and identifier of both is in it,
 * nothing of the kind is added, it is not much longer than the facts together nor longer than a fact may be, and it
 * keeps the script of the fact that stays. The model writes, the code decides whether the text may reach the owner's card.
 *
 * spec: brief/memory.md#слияние
 */
final class MergeGuard {
    public const LOST = 'lost';
    public const ADDED = 'added';
    public const LONG = 'long';
    public const SCRIPT = 'script';
    public const EMPTY = 'empty';
    /** characters over both facts a merged text may take: the words that join them */
    public const SLACK = 40;

    /** @return ?string why the text is refused (one of the constants); null — it may be proposed */
    public static function problem(Fact $keep, Fact $drop, string $text): ?string {
        $text = trim($text);
        if ($text === '') {
            return self::EMPTY;
        }
        $length = mb_strlen($text);
        if ($length > ContentScreen::FACT_MAX || $length > mb_strlen($keep->text) + mb_strlen($drop->text) + self::SLACK) {
            return self::LONG;
        }
        $before = array_fill_keys([...self::marks($keep->text), ...self::marks($drop->text)], true);
        $after = array_fill_keys(self::marks($text), true);
        if (array_diff_key($before, $after) !== []) {
            return self::LOST;
        }
        if (array_diff_key($after, $before) !== []) {
            return self::ADDED;
        }
        if (self::script($text) !== self::script($keep->text)) {
            return self::SCRIPT;
        }
        return null;
    }

    /**
     * Numbers and identifiers: what a retelling must not change — digits with their separators, and Latin words joined
     * by a dot, dash, underscore, @ or slash or holding digits (pve-01, example.com, gpt-4o).
     *
     * @return list<string> lowercase
     */
    public static function marks(string $text): array {
        $marks = [];
        preg_match_all('~\d+(?:[.,:/-]\d+)*~u', $text, $numbers);
        preg_match_all('~[A-Za-z0-9]+(?:[._@/-][A-Za-z0-9]+)+|[A-Za-z]+\d[A-Za-z0-9]*~u', $text, $words);
        foreach ([...$numbers[0], ...$words[0]] as $mark) {
            $marks[mb_strtolower($mark)] = true;
        }
        return array_map(strval(...), array_keys($marks));
    }

    /** The script most letters of the text are in: cyrillic, han, latin or other. */
    public static function script(string $text): string {
        $top = 'other';
        $most = 0;
        foreach (['cyrillic' => '~\p{Cyrillic}~u', 'han' => '~\p{Han}~u', 'latin' => '~\p{Latin}~u'] as $script => $letters) {
            $count = (int) preg_match_all($letters, $text);
            if ($count > $most) {
                [$top, $most] = [$script, $count];
            }
        }
        return $top;
    }
}

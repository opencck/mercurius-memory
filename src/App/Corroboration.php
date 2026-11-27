<?php

declare(strict_types=1);

namespace Mercurius\Memory\App;

use DateTimeImmutable;
use DateTimeZone;
use Mercurius\Memory\Domain\TextAnalyzer;

/**
 * In how many different local days a person said a fact in their own messages. What was said on two days holds; what
 * was said once may be passing. Code counts, no model is asked.
 *
 * spec: brief/memory.md#консолидация-dreaming — what the owner repeated on different days; machine text does not count
 * ref: references/hermes-dreaming/skills/dreaming/references/scoring.md@46d2f9e:L44-L52 — a human message mentions a
 *      fact by two shared stems or one rare word (8 letters for Latin, 10 for others), window 60 days; L126-L128 —
 *      distinct local days; dream_lang.py@46d2f9e:L175-L180 (NEGATION_WORDS) — a message with a negation the fact has
 *      not (or the other way) says something else; here by sentence, the Russian and English words of the list
 */
final class Corroboration {
    public const WINDOW_DAYS = 60;
    /** the newest lines of a person read for one fact */
    public const LINES = 2000;

    public function __construct(private readonly TextAnalyzer $analyzer) {}

    /**
     * @param list<array{string, int}> $lines a person's own messages: the text as stored and unix seconds
     */
    public function days(string $fact, array $lines, DateTimeZone $zone): int {
        $words = $this->words($fact);
        if ($words === []) {
            return 0;
        }
        $negated = TextAnalyzer::negates($fact);
        $need = min(2, count($words));
        $days = [];
        foreach ($lines as [$content, $at]) {
            $day = (new DateTimeImmutable('@' . $at))->setTimezone($zone)->format('Y-m-d');
            if (isset($days[$day])) {
                continue;
            }
            foreach (preg_split('~[.!?;\n]+~u', self::ownWords($content)) ?: [] as $sentence) {
                if (trim($sentence) === '' || TextAnalyzer::negates($sentence) !== $negated) {
                    continue;
                }
                $keys = array_fill_keys($this->analyzer->keys($sentence), true);
                $shared = 0;
                $rare = false;
                foreach ($words as [$wordKeys, $distinctive]) {
                    if (array_intersect_key($wordKeys, $keys) !== []) {
                        $shared++;
                        $rare = $rare || $distinctive;
                    }
                }
                if ($shared >= $need || $rare) {
                    $days[$day] = true;
                    break;
                }
            }
        }
        return count($days);
    }

    /**
     * The person's own words of a stored message: what the app added — the header with the message they answer, recalled
     * facts, a forwarded page or file as an untrusted block, the note about skills — is not what they said.
     */
    public static function ownWords(string $content): string {
        // a block of outside data may stand before the person's words as well as after them
        $content = preg_replace('~<untrusted-data\b.*?</untrusted-data>~su', ' ', $content) ?? $content;
        $cut = strpos($content, '<untrusted-data');
        $content = $cut === false ? $content : substr($content, 0, $cut);
        // ref: references/hermes-dreaming/skills/dreaming/scripts/dream.py@46d2f9e:L1168-L1176 (REPLY_QUOTE_RE),
        //      L1832-L1834 (_own_words) — the message a person answers is left out: a bare «ок» under the agent's
        //      words said nothing of them. Here the header the application puts first names the message by its number
        //      and quotes the answered one in «…»; the quote ends at its mark before "]", not at a "]" inside it
        $content = preg_replace('~^\s*\[(?=[^\]\n«]*#\d)(?:[^\]\n«]|«.*?»)*\]\s*~su', '', $content, 1) ?? $content;
        $note = strpos($content, "\n\n" . \Mercurius\Memory\t('skill.library.changed'));
        return trim($note === false ? $content : substr($content, 0, $note));
    }

    /**
     * The fact's words, each as its keys and whether it is rare enough to tell the fact alone.
     *
     * @return list<array{array<string, true>, bool}>
     */
    private function words(string $fact): array {
        preg_match_all('~[\p{L}\p{N}_]+(?:[.\-/][\p{L}\p{N}_]+)*~u', str_replace(['ё', 'Ё'], ['е', 'Е'], $fact), $m);
        $words = [];
        $seen = [];
        foreach ($m[0] as $token) {
            $keys = $this->analyzer->keys($token);
            if ($keys === [] || isset($seen[$keys[0]])) {
                continue;
            }
            $seen[$keys[0]] = true;
            $latin = preg_match('~^[a-z0-9_.\-/]+$~i', $token) === 1;
            $distinctive = TextAnalyzer::isIdentifier($keys[0]) || mb_strlen($token) >= ($latin ? 8 : 10);
            $words[] = [array_fill_keys($keys, true), $distinctive];
        }
        return $words;
    }
}

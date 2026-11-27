<?php

declare(strict_types=1);

namespace Mercurius\Memory\Domain;

use Closure;

use function Mercurius\Memory\t;

/**
 * Why a text must not go into memory, or null. Memory comes back into every later conversation, so one poisoned
 * fact is a standing instruction: no commands to the assistant, no role markup, no secrets, nothing invisible.
 *
 * spec: brief/memory.md#как-сделано-области-и-факты — memory is screened before it is stored and given as data when recalled
 * ref: references/hermes-plugin-holographic/screen.py@9b49943:L1023-L1087 (_RE_ROLE_MARKUP, _RE_BLOB, _RE_IMAGE_EXFIL,
 *      _hidden_char, _fact_problem) — the same checks in the same order; injection phrases for Russian and English
 *      only, and the secret check is the one the tools use (passed in), not a second list of credential words
 * ref: references/hermes-plugin-holographic/screen.py@9b49943:L133-L177 (_RE_IMPERATIVE_OVERRIDE, _RE_PROMPT_REQUEST,
 *      _prompt_extraction_in) — an order to drop the context followed by a request for the system prompt; the same
 *      shape in Russian is ours, the check of negations is not taken
 * ref: references/hermes-plugin-holographic/screen.py@9b49943:L987-L1015 (_CONFUSABLE, _plain_letters) — letters drawn
 *      as Latin ones are read as Latin; without intl there is no NFKC, so full-width forms are folded by their code
 *      and combining marks are dropped; Latin letters drawn into a Russian word are read as Cyrillic too
 */
final class ContentScreen {
    public const FACT_MAX = 1000;

    /** the space before a role is that of its own line: "^\s*" ran over every blank line after each line start */
    private const ROLE_MARKUP = '~(?im)^[^\S\n]*(?:system|assistant|user|tool|система|ассистент)\s*[:：]|</?(?:tool_call|function_call|system|assistant)\b|<\|~u';
    private const BLOB = '~[A-Za-z0-9+/=_-]{200,}~';
    /** the alt text is read up to the next "![": each "![" no longer reads to the end of its line */
    private const IMAGE = '~!\[[^\]\n!]*(?:!(?!\[)[^\]\n!]*)*\]\s*[(\[]|<\s*img\b~iu';
    private const QUOTED = '~"[^"\n]*"|\'[^\'\n]*\'|«[^»\n]*»|“[^”\n]*”~u';
    private const INJECTION = '~(?:ignore|disregard|forget|override)\s+(?:all\s+|any\s+)?(?:of\s+)?(?:your\s+(?:own\s+)?'
        . '(?:(?:previous|prior|above|earlier|system|original|all)\s+)?|(?:the\s+)?(?:previous|prior|above|earlier|system|original|all)\s+)'
        . '(?:instructions?|prompts?|rules|guidelines)|your\s+(?:new\s+)?instructions\s+are|you\s+are\s+now\s+(?:in\s+)?(?:dan|developer|jailbreak)'
        . '|(?:игнорируй|игнорируйте|забудь|забудьте|отмени|отмените|не\s+слушай|не\s+выполняй)\s+(?:все\s+|всё\s+)?'
        . '(?:свои\s+|твои\s+|ваши\s+|прежние\s+|предыдущие\s+|прошлые\s+|системные\s+)+(?:инструкци\w*|правил\w*|указани\w*|промпт\w*)'
        . '|(?:игнорируй|забудь)\s+(?:все|всё)\s+(?:инструкци\w*|правил\w*|указани\w*)'
        . '|(?:твои|ваши)\s+новые\s+(?:инструкци\w*|правила)|теперь\s+ты\s+(?:—\s*|-\s*)?(?:dan|разработчик|без\s+ограничений)~iu';

    /** the verbs an order to drop the context begins with: without one the longer pattern is not tried */
    private const OVERRIDE = '~\b(?:ignore|disregard|forget|override|игнорируй|забудь|отбрось|обращай)~iu';
    /** an imperative to drop what came before, at the start of a sentence, then in the next clause a request for the prompt */
    private const EXTRACTION = '~(?:(?:\A|(?<=[.!?;]))\s*|(?<=\n)[^\S\n]*)(?:(?:please|now)\s+)?(?:(?:you\s+(?:must|should|need\s+to)|i\s+want\s+you\s+to)\s+)?'
        . '(?:ignore|disregard|forget|override)\b[^.!?;\n]{0,100}?\b(?:above|earlier|prior|previous|before|preceding|context|instructions?|rules?)\b'
        . '[^.!?;,\n]{0,40}?\s*(?:(?:\b(?:and|then|instead)\b[\s,]*)+|[,;.!?]\s*(?:\b(?:and|then|instead)\b[\s,]*)*)(?:(?:please|now)\b[\s,]*)?'
        . '(?:print|show|reveal|output|dump|repeat|quote|write\s+out|tell\s+me|display)\b[^.!?;\n]{0,80}?'
        . '\b(?:system\s+prompt|hidden\s+instructions?|developer\s+message|initial\s+instructions?)\b'
        . '|(?:(?:\A|(?<=[.!?;]))\s*|(?<=\n)[^\S\n]*)(?:(?:пожалуйста|теперь)[\s,]+)?(?:игнорируй|забудь|отбрось|не\s+обращай\s+внимания\s+на)(?:те)?\b[^.!?;\n]{0,100}?'
        . '\b(?:выше|ранее|раньше|предыдущ\w*|прежн\w*|прошл\w*|контекст\w*|инструкци\w*|правил\w*|указани\w*)'
        . '[^.!?;,\n]{0,40}?\s*(?:(?:\b(?:и|а|затем|потом)\b[\s,]*)+|[,;.!?]\s*(?:\b(?:и|а|затем|потом)\b[\s,]*)*)(?:(?:пожалуйста|теперь)\b[\s,]*)?'
        . '(?:покажи|выведи|напечатай|напиши|раскрой|повтори|процитируй|скажи|выдай|пришли)(?:те)?\b[^.!?;\n]{0,80}?'
        . '(?:системн\w+\s+(?:промпт\w*|инструкци\w*|сообщени\w*)|скрыт\w+\s+инструкци\w*|начальн\w+\s+инструкци\w*|сообщени\w+\s+разработчика)~iu';
    /** Cyrillic and Greek letters drawn as Latin ones */
    private const TO_LATIN = ['а' => 'a', 'е' => 'e', 'о' => 'o', 'р' => 'p', 'с' => 'c', 'у' => 'y', 'х' => 'x', 'і' => 'i', 'ј' => 'j', 'ѕ' => 's',
        'ԁ' => 'd', 'ԛ' => 'q', 'ԝ' => 'w', 'А' => 'A', 'В' => 'B', 'Е' => 'E', 'К' => 'K', 'М' => 'M', 'Н' => 'H', 'О' => 'O', 'Р' => 'P', 'С' => 'C',
        'Т' => 'T', 'Х' => 'X', 'І' => 'I', 'Ј' => 'J', 'Ѕ' => 'S', 'Α' => 'A', 'Β' => 'B', 'Ε' => 'E', 'Η' => 'H', 'Ι' => 'I', 'Κ' => 'K', 'Μ' => 'M',
        'Ν' => 'N', 'Ο' => 'O', 'Ρ' => 'P', 'Τ' => 'T', 'Χ' => 'X', 'Υ' => 'Y', 'Ζ' => 'Z', 'ο' => 'o', 'α' => 'a'];
    /** Latin letters drawn as Cyrillic ones */
    private const TO_CYRILLIC = ['a' => 'а', 'e' => 'е', 'o' => 'о', 'p' => 'р', 'c' => 'с', 'y' => 'у', 'x' => 'х', 'A' => 'А', 'B' => 'В', 'E' => 'Е',
        'K' => 'К', 'M' => 'М', 'H' => 'Н', 'O' => 'О', 'P' => 'Р', 'C' => 'С', 'T' => 'Т', 'X' => 'Х'];

    /** @var Closure(string): bool */
    private readonly Closure $hasSecret;

    /** @param (Closure(string): bool)|null $hasSecret true when the text holds a password, key or token */
    public function __construct(?Closure $hasSecret = null) {
        $this->hasSecret = $hasSecret ?? static fn(string $text): bool => false;
    }

    public function problem(string $text, int $max = self::FACT_MAX): ?string {
        $text = trim($text);
        if ($text === '') {
            return t('domain.content_screen.pustoy_tekst');
        }
        if (mb_strlen($text) > $max) {
            return sprintf(t('domain.content_screen.slishkom_dlinno_simvolov_ne_bolshe'), mb_strlen($text), $max);
        }
        if (str_starts_with($text, '{') || str_starts_with($text, '[')) {
            try {
                json_decode($text, flags: JSON_THROW_ON_ERROR);
                return t('domain.content_screen.pohozhe_na_json_ili_vyvod');
            } catch (\JsonException) {
            }
        }
        if (preg_match(self::ROLE_MARKUP, $text) === 1) {
            return t('domain.content_screen.soderzhit_razmetku_roley_ili_vyzova');
        }
        $unquoted = preg_replace(self::QUOTED, ' ', $text) ?? $text;
        foreach (self::readings($unquoted) as $reading) {
            if (preg_match(self::INJECTION, $reading) === 1 || (preg_match(self::OVERRIDE, $reading) === 1 && preg_match(self::EXTRACTION, $reading) === 1)) {
                return t('domain.content_screen.chitaetsya_kak_ukazanie_assistentu_a');
            }
        }
        if (($this->hasSecret)($text)) {
            return t('domain.content_screen.pohozhe_na_sekret_parol_klyuch');
        }
        if (self::hidden($text)) {
            return t('domain.content_screen.soderzhit_nevidimye_simvoly_formatirovan');
        }
        if (preg_match(self::IMAGE, $text) === 1) {
            return t('domain.content_screen.soderzhit_izobrazhenie_klient_kotoryy_eg');
        }
        if (preg_match(self::BLOB, $text) === 1) {
            return t('domain.content_screen.soderzhit_dlinnuyu_zakodirovannuyu_strok');
        }
        return null;
    }

    /**
     * The text as written and as its lookalike letters read: full-width forms as plain ones, no combining marks, and a
     * word of two alphabets once all in Latin and once all in Cyrillic. A text of one alphabet is read once.
     *
     * @return list<string>
     */
    private static function readings(string $text): array {
        if (preg_match('~[^\x00-\x7F]~', $text) !== 1) {
            return [$text];
        }
        // U+FF01–U+FF5E are the ASCII characters 0x21–0x7E in full width
        $plain = preg_replace_callback('~[\x{FF01}-\x{FF5E}]~u', static fn(array $m): string => chr((int) mb_ord($m[0]) - 0xFEE0), $text) ?? $text;
        $plain = preg_replace('~[\x{0300}-\x{036F}]+~u', '', $plain) ?? $plain;
        $mixed = '~\p{L}+~u';
        $both = static fn(string $word): bool => preg_match('~[A-Za-z]~', $word) === 1 && preg_match('~[\p{Cyrillic}\p{Greek}]~u', $word) === 1;
        $latin = preg_replace_callback($mixed, static fn(array $m): string => $both($m[0]) ? strtr($m[0], self::TO_LATIN) : $m[0], $plain) ?? $plain;
        $cyrillic = preg_replace_callback($mixed, static fn(array $m): string => $both($m[0]) ? strtr($m[0], self::TO_CYRILLIC) : $m[0], $plain) ?? $plain;
        return array_values(array_unique([$text, $latin, $cyrillic]));
    }

    /** Format, control and private-use characters other than line breaks and tabs. */
    private static function hidden(string $text): bool {
        return preg_match('~[\p{Cf}\p{Co}]|[^\P{Cc}\n\r\t]~u', $text) === 1;
    }
}

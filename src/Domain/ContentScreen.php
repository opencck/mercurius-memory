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
 * ref: references/hermes-plugin-holographic/screen.py@4a794f8:L872-L937 (_RE_ROLE_MARKUP, _RE_BLOB, _RE_IMAGE_EXFIL,
 *      _hidden_char, _fact_problem) — the same checks in the same order; injection phrases for Russian and English
 *      only, and the secret check is the one the tools use (passed in), not a second list of credential words
 */
final class ContentScreen {
    public const FACT_MAX = 1000;

    private const ROLE_MARKUP = '~(?im)^\s*(?:system|assistant|user|tool|система|ассистент)\s*[:：]|</?(?:tool_call|function_call|system|assistant)\b|<\|~u';
    private const BLOB = '~[A-Za-z0-9+/=_-]{200,}~';
    private const IMAGE = '~!\[[^\]\n]*\]\s*[(\[]|<\s*img\b~iu';
    private const QUOTED = '~"[^"\n]*"|\'[^\'\n]*\'|«[^»\n]*»|“[^”\n]*”~u';
    private const INJECTION = '~(?:ignore|disregard|forget|override)\s+(?:all\s+|any\s+)?(?:of\s+)?(?:your\s+(?:own\s+)?'
        . '(?:(?:previous|prior|above|earlier|system|original|all)\s+)?|(?:the\s+)?(?:previous|prior|above|earlier|system|original|all)\s+)'
        . '(?:instructions?|prompts?|rules|guidelines)|your\s+(?:new\s+)?instructions\s+are|you\s+are\s+now\s+(?:in\s+)?(?:dan|developer|jailbreak)'
        . '|(?:игнорируй|игнорируйте|забудь|забудьте|отмени|отмените|не\s+слушай|не\s+выполняй)\s+(?:все\s+|всё\s+)?'
        . '(?:свои\s+|твои\s+|ваши\s+|прежние\s+|предыдущие\s+|прошлые\s+|системные\s+)+(?:инструкци\w*|правил\w*|указани\w*|промпт\w*)'
        . '|(?:игнорируй|забудь)\s+(?:все|всё)\s+(?:инструкци\w*|правил\w*|указани\w*)'
        . '|(?:твои|ваши)\s+новые\s+(?:инструкци\w*|правила)|теперь\s+ты\s+(?:—\s*|-\s*)?(?:dan|разработчик|без\s+ограничений)~iu';

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
        if (preg_match(self::INJECTION, $unquoted) === 1) {
            return t('domain.content_screen.chitaetsya_kak_ukazanie_assistentu_a');
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

    /** Format, control and private-use characters other than line breaks and tabs. */
    private static function hidden(string $text): bool {
        return preg_match('~[\p{Cf}\p{Co}]|[^\P{Cc}\n\r\t]~u', $text) === 1;
    }
}

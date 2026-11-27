<?php

declare(strict_types=1);

namespace Mercurius\Memory\Domain;

use DateTimeImmutable;

/**
 * Relative day words of a fact, made absolute when it is written: «встреча завтра в 10:00» read a month later is a
 * meeting that is always tomorrow. The word stays and its day follows it: «завтра (2026-11-28)».
 *
 * spec: brief/memory.md#запись-сведения-о-людях-и-забывание — a relative day gets its date
 * ref: references/hermes-plugin-holographic/dates.py@9b49943:L14-L47 (_WORDS, _RE_DAY, _RE_KEEP, absolutize) — the
 *      same words and rules: only words that name one day whatever stands around them («через 3 дня», «в пятницу»,
 *      «на следующей неделе» are left), nothing inside quotes, inline code or a URL, a word already followed by a date
 *      is not dated again
 */
final class RelativeDays {
    /** the longer phrases first: «the day after tomorrow» is not «tomorrow» */
    private const OFFSETS = [
        'the day after tomorrow' => 2, 'the day before yesterday' => -2, 'tomorrow' => 1, 'yesterday' => -1, 'today' => 0,
        'tonight' => 0, 'послезавтра' => 2, 'позавчера' => -2, 'завтра' => 1, 'вчера' => -1, 'сегодня' => 0,
    ];
    private const KEEP = '«[^»]*»|"[^"]*"|“[^”]*”|„[^“”]*[“”]|`[^`]*`|https?://\S+|www\.\S+';

    /** @param ?DateTimeImmutable $today the day of the one whose memory it is; null — not known, nothing is added */
    public static function absolutize(string $text, ?DateTimeImmutable $today): string {
        if ($today === null || $text === '') {
            return $text;
        }
        $words = implode('|', array_map(static fn(string $w): string => str_replace(' ', '\s+', $w), array_keys(self::OFFSETS)));
        // not a part of a longer word («сегодняшний»), not a possessive («today's»), not followed by a date already
        $pattern = '~(?<![\p{L}\p{N}_-])(' . $words . ')(?![\p{L}\p{N}_-])(?![\'’]s\b)(?!\s*\(?\d{4}-\d{2}-\d{2})~iu';
        $date = static function (array $m) use ($today): string {
            /** @var mixed $whole */
            $whole = $m[0] ?? null;
            $word = is_string($whole) ? $whole : '';
            $days = self::OFFSETS[mb_strtolower(preg_replace('~\s+~u', ' ', $word) ?? $word)] ?? null;
            return $days === null ? $word : sprintf('%s (%s)', $word, $today->modify(sprintf('%+d days', $days))->format('Y-m-d'));
        };
        // the quoted spans come back as the odd pieces and stay as they are
        $pieces = preg_split('~(' . self::KEEP . ')~iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($pieces === false) {
            return $text;
        }
        foreach ($pieces as $i => $piece) {
            if ($i % 2 === 0) {
                $pieces[$i] = preg_replace_callback($pattern, $date, $piece) ?? $piece;
            }
        }
        return implode('', $pieces);
    }
}

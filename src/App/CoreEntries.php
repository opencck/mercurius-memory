<?php

declare(strict_types=1);

namespace Mercurius\Memory\App;

use InvalidArgumentException;

use function Mercurius\Memory\t;

/**
 * The profile and the summary of layer 1 as entries: the agent adds, replaces or removes one entry instead of
 * rewriting the whole text. An entry is found by its text: the entry equal to it first, then the one that contains
 * it — and only one; none or several is an error that names the closest entries. A text written before entries (one
 * block of prose) is one entry.
 *
 * spec: BRIEF.md#всегда-в-контексте — layer 1 is edited by entries
 * ref: references/hermes-agent/tools/memory_tool_store.py@e96221fa:L26 (ENTRY_DELIMITER), L98-L109
 *      (_find_unique_match), L112-L125 (_no_match_error), L403-L474 (add, replace, remove) — the delimiter, an exact
 *      entry before a substring, ambiguity refused, the closest entries in the error; a duplicate is not added
 */
final class CoreEntries {
    public const DELIMITER = "\n§\n";
    public const EDITS = ['all', 'add', 'replace', 'remove'];

    /** @return list<string> the entries, trimmed, without empty ones */
    public static function split(string $text): array {
        return array_values(array_filter(array_map('trim', explode(self::DELIMITER, str_replace("\r\n", "\n", $text))), static fn(string $e): bool => $e !== ''));
    }

    /**
     * The text after the edit.
     *
     * @throws InvalidArgumentException when the edit cannot be made: an unknown edit, no text, no or several entries
     */
    public static function apply(string $current, string $edit, string $old, string $new): string {
        $new = trim($new);
        $old = trim($old);
        if ($edit === 'all') {
            return $new;
        }
        $entries = self::split($current);
        switch ($edit) {
            case 'add':
                if ($new === '') {
                    throw new InvalidArgumentException(t('app.core_entries.no_text'));
                }
                if (!in_array($new, $entries, true)) {
                    $entries[] = $new;
                }
                return implode(self::DELIMITER, $entries);
            case 'replace':
            case 'remove':
                if ($old === '') {
                    throw new InvalidArgumentException(t('app.core_entries.no_old'));
                }
                if ($edit === 'replace' && $new === '') {
                    throw new InvalidArgumentException(t('app.core_entries.no_text'));
                }
                $index = self::find($entries, $old);
                if ($edit === 'replace') {
                    $entries[$index] = $new;
                } else {
                    unset($entries[$index]);
                }
                return implode(self::DELIMITER, array_values($entries));
            default:
                throw new InvalidArgumentException(t('app.core_entries.unknown_edit') . $edit);
        }
    }

    /**
     * @param list<string> $entries
     * @throws InvalidArgumentException
     */
    private static function find(array $entries, string $old): int {
        $exact = array_keys($entries, $old, true);
        $matches = $exact !== [] ? $exact : array_keys(array_filter($entries, static fn(string $e): bool => mb_stripos($e, $old) !== false));
        if (count(array_unique(array_map(static fn(int $i): string => $entries[$i], $matches))) > 1) {
            throw new InvalidArgumentException(t('app.core_entries.ambiguous') . ' ' . implode(' | ', array_map(static fn(int $i): string => $entries[$i], $matches)));
        }
        if ($matches === []) {
            $closest = self::closest($entries, $old);
            throw new InvalidArgumentException(t('app.core_entries.no_match') . ($closest === [] ? '' : ' ' . t('app.core_entries.closest') . ' ' . implode(' | ', $closest)));
        }
        return $matches[0];
    }

    /**
     * The entries sharing most words with the text, at least 30% of its words.
     *
     * @param list<string> $entries
     * @return list<string>
     */
    private static function closest(array $entries, string $old): array {
        $words = static function (string $s): array {
            $split = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($s), -1, PREG_SPLIT_NO_EMPTY);
            return array_values(array_unique($split === false ? [] : $split));
        };
        $wanted = $words($old);
        if ($wanted === []) {
            return [];
        }
        $scored = [];
        foreach ($entries as $i => $entry) {
            $share = count(array_intersect($wanted, $words($entry))) / count($wanted);
            if ($share >= 0.3) {
                $scored[$i] = $share;
            }
        }
        arsort($scored);
        return array_map(static fn(int $i): string => $entries[$i], array_slice(array_keys($scored), 0, 3));
    }
}

<?php

declare(strict_types=1);

namespace Mercurius\Memory\App;

use Mercurius\Memory\Domain\AliasStore;
use Mercurius\Memory\Domain\Chinese;
use Mercurius\Memory\Domain\ContentScreen;
use Mercurius\Memory\Domain\MemoryStore;
use Mercurius\Memory\Domain\TextAnalyzer;

use function Mercurius\Memory\t;

/**
 * The owner's table of words that mean one thing («прокс» — «proxmox» — «普罗克斯»): the agent adds a group itself, the
 * owner sees and edits the table. Each group is one key of search for its words, in facts and in questions; a change
 * keys the facts again.
 *
 * spec: brief/memory.md#словари — the owner's table
 */
final class Aliases {
    public const MAX_GROUPS = 500;
    public const MAX_WORDS = 8;
    private const WORD_MAX = 40;

    public function __construct(
        private readonly AliasStore $store,
        private readonly TextAnalyzer $analyzer,
        private readonly MemoryStore $memory,
        private readonly ContentScreen $screen,
    ) {}

    /** Gives the analyzer the table as it is stored: at the start, before facts are keyed again. */
    public function load(): void {
        $this->analyzer->useAliases($this->store->groups());
    }

    /**
     * Adds words as one group, or to the group one of them is already in.
     *
     * @param list<string> $words
     * @return array{bool, string} whether the table changed, and what to say
     */
    public function add(array $words, ?int $by): array {
        $words = array_values(array_unique(array_filter(array_map(self::normal(...), $words), static fn(string $w): bool => $w !== '')));
        foreach ($words as $word) {
            $problem = self::short($word) ? sprintf(t('app.aliases.short'), $word) : $this->screen->problem($word, self::WORD_MAX);
            if ($problem !== null) {
                return [false, $problem];
            }
        }
        $groups = $this->store->groups();
        $into = null;
        foreach ($groups as $id => $group) {
            if (array_intersect($group, $words) !== []) {
                $into = $id;
                break;
            }
        }
        $all = $into === null ? $words : array_values(array_unique([...$groups[$into], ...$words]));
        if (count($all) < 2) {
            return [false, t('app.aliases.too_few')];
        }
        if (count($all) > self::MAX_WORDS) {
            return [false, sprintf(t('app.aliases.too_many'), self::MAX_WORDS)];
        }
        if ($into === null && count($groups) >= self::MAX_GROUPS) {
            return [false, sprintf(t('app.aliases.full'), self::MAX_GROUPS)];
        }
        if ($into !== null && $all === $groups[$into]) {
            return [false, sprintf(t('app.aliases.already'), $into, implode(', ', $all))];
        }
        if ($into === null) {
            $into = $this->store->add($all, $by);
        } else {
            $this->store->replace($into, $all);
        }
        $this->changed();
        return [true, sprintf(t('app.aliases.added'), $into, implode(', ', $all))];
    }

    /** @return array{bool, string} */
    public function remove(int $id): array {
        if (!$this->store->remove($id)) {
            return [false, sprintf(t('app.aliases.not_found'), $id)];
        }
        $this->changed();
        return [true, sprintf(t('app.aliases.removed'), $id)];
    }

    /** The table, a group a line. */
    public function list(): string {
        $lines = [];
        foreach ($this->store->groups() as $id => $words) {
            $lines[] = sprintf('#%d: %s', $id, implode(', ', $words));
        }
        return $lines === [] ? t('app.aliases.none') : implode("\n", $lines);
    }

    private function changed(): void {
        $this->load();
        $this->memory->rekey($this->analyzer->keys(...));
    }

    private static function normal(string $word): string {
        return trim(mb_strtolower(str_replace(['ё', 'Ё'], ['е', 'Е'], $word)));
    }

    /** A word of one or two letters is too short to mean one thing; a Chinese word of two characters is a word. */
    private static function short(string $word): bool {
        return Chinese::hasHan($word) ? mb_strlen($word) < 2 : mb_strlen($word) < 3;
    }
}

<?php

declare(strict_types=1);

namespace Mercurius\Memory\App;

use Mercurius\Memory\Domain\SleepJudge;
use Mercurius\Memory\Domain\Verdict;
use Mercurius\Memory\Domain\VerdictCache;

/**
 * The judge with a memory of what it already said: a pair whose texts and prompt did not change is answered from the
 * cache; only the rest go to the model, in one call of the inner judge.
 *
 * spec: brief/memory.md#судья-пары
 * ref: references/hermes-dreaming/skills/dreaming/scripts/dream_judge.py@46d2f9e:L37, L404-L410 (PROMPT_VERSION,
 *      cache_key) — the version of the prompt is part of every key: a verdict on other words is no verdict on these
 */
final class CachedJudge implements SleepJudge {
    public function __construct(
        private readonly SleepJudge $inner,
        private readonly VerdictCache $cache,
        private readonly string $promptVersion,
    ) {}

    public function judge(array $pairs): array {
        $verdicts = [];
        $ask = [];
        foreach ($pairs as $i => [$a, $b]) {
            $hit = $this->cache->find($this->key($a->text, $b->text));
            $verdicts[$i] = $hit;
            if ($hit === null) {
                $ask[$i] = [$a, $b];
            }
        }
        if ($ask !== []) {
            $answers = $this->inner->judge(array_values($ask));
            foreach (array_keys($ask) as $n => $i) {
                $verdict = $answers[$n] ?? null;
                $verdicts[$i] = $verdict;
                if ($verdict instanceof Verdict) {
                    $this->cache->keep($this->key($ask[$i][0]->text, $ask[$i][1]->text), $ask[$i][0]->id, $ask[$i][1]->id, $verdict);
                }
            }
        }
        ksort($verdicts);
        return array_values($verdicts);
    }

    public function spent(): array {
        return $this->inner->spent();
    }

    private function key(string $a, string $b): string {
        return hash('sha256', $this->promptVersion . "\x1f" . $a . "\x1f" . $b);
    }
}

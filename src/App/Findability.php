<?php

declare(strict_types=1);

namespace Mercurius\Memory\App;

use Mercurius\Memory\Domain\Fact;
use Mercurius\Memory\Domain\MemoryStore;
use Mercurius\Memory\Domain\QuestionStore;
use Mercurius\Memory\Domain\SleepQuestions;

/**
 * Whether memory finds its facts by questions put in other words: the model of sleep writes them once per version of a
 * fact, the code asks them through search before the words of the night and after. Not found goes first to get words;
 * the words are never taken back, a fact not found after two nights with words is named to the owner.
 *
 * spec: brief/memory.md#самопроверка-поиска
 * ref: docs/notes/google-language-models-need-sleep.md:L43-L45 — most of the gain comes from checking an edit on the
 *      task; here the task is finding the fact, and the check only orders the work and reports, it removes nothing
 */
final class Findability {
    /** facts that get questions in one night: one call of the model */
    public const ASK = 20;
    /** facts asked about in one night: the check is code, but not without an end */
    public const FACTS = 300;
    public const TOP = 5;
    public const QUESTIONS = 3;
    public const QUESTION_MAX = 200;
    /** nights with words not found before the owner is told */
    public const NIGHTS = 2;

    public function __construct(
        private readonly MemoryStore $store,
        private readonly Recall $recall,
        private readonly QuestionStore $questions,
        private readonly SleepQuestions $model,
    ) {}

    /** @param list<Fact> $facts the facts the model may see */
    public function prepare(array $facts): void {
        $need = [];
        foreach ($facts as $fact) {
            if ($this->questions->questions($fact->id, $fact->version) === null) {
                $need[] = $fact;
                if (count($need) >= self::ASK) {
                    break;
                }
            }
        }
        if ($need === []) {
            return;
        }
        $asked = $this->model->questions($need);
        foreach ($need as $i => $fact) {
            $list = [];
            foreach ($asked[$i] ?? [] as $question) {
                $question = trim($question);
                if ($question !== '' && mb_strlen($question) <= self::QUESTION_MAX) {
                    $list[] = $question;
                }
            }
            if ($list !== []) {
                $this->questions->save($fact->id, $fact->version, array_slice($list, 0, self::QUESTIONS));
            }
        }
    }

    /**
     * @param list<Fact> $facts
     * @return array{int, int, float, list<int>} facts found, facts with questions, other facts among the first per
     *         question, the facts not found
     */
    public function measure(array $facts): array {
        [$found, $total, $extra, $asked, $missing] = [0, 0, 0, 0, []];
        foreach (array_slice($facts, 0, self::FACTS) as $fact) {
            $now = $this->store->fact($fact->id) ?? $fact;
            $questions = $this->questions->questions($now->id, $now->version);
            if ($questions === null || !$now->active) {
                continue;
            }
            $total++;
            $hit = false;
            foreach ($questions as $question) {
                $ids = array_map(static fn(Fact $f): int => $f->id, array_slice($this->recall->find($question, [$now->scope]), 0, self::TOP));
                $asked++;
                $hit = $hit || in_array($now->id, $ids, true);
                $extra += count(array_diff($ids, [$now->id]));
            }
            if ($hit) {
                $found++;
            } else {
                $missing[] = $now->id;
            }
        }
        return [$found, $total, $asked === 0 ? 0.0 : (float) $extra / (float) $asked, $missing];
    }

    /**
     * After the night: a fact with words still not found counts one more night; found starts again.
     *
     * @param list<Fact> $facts
     * @param list<int> $missing
     * @return list<int> the facts to name to the owner now
     */
    public function settle(array $facts, array $missing): array {
        $name = [];
        $lost = array_fill_keys($missing, true);
        foreach ($facts as $fact) {
            $now = $this->store->fact($fact->id);
            if ($now === null || $this->questions->questions($now->id, $now->version) === null) {
                continue;
            }
            if (!isset($lost[$now->id])) {
                $this->questions->found($now->id);
            } elseif ($now->words !== [] && $this->questions->miss($now->id) === self::NIGHTS) {
                $name[] = $now->id;
            }
        }
        return $name;
    }

    /** @return array{int, int, float} */
    public function spent(): array {
        return $this->model->spent();
    }
}

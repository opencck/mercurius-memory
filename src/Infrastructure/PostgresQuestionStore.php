<?php

declare(strict_types=1);

namespace Mercurius\Memory\Infrastructure;

use Amp\Postgres\PostgresLink;
use Mercurius\Memory\Domain\QuestionStore;

final class PostgresQuestionStore implements QuestionStore {
    public function __construct(private readonly PostgresLink $db) {}

    public function questions(int $factId, int $version): ?array {
        $row = $this->db->execute('SELECT questions::text AS questions FROM memory_fact_questions WHERE fact_id = ? AND version = ?', [$factId, $version])->fetchRow();
        if ($row === null || !isset($row['questions']) || !is_string($row['questions'])) {
            return null;
        }
        try {
            /** @var mixed $list */
            $list = json_decode($row['questions'], true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($list) ? array_values(array_filter($list, 'is_string')) : null;
    }

    public function save(int $factId, int $version, array $questions): void {
        $this->db->execute(
            'INSERT INTO memory_fact_questions (fact_id, version, questions) VALUES (?, ?, ?::jsonb)
             ON CONFLICT (fact_id) DO UPDATE SET version = EXCLUDED.version, questions = EXCLUDED.questions, misses = 0',
            [$factId, $version, json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
        );
    }

    public function miss(int $factId): int {
        $row = $this->db->execute('UPDATE memory_fact_questions SET misses = misses + 1 WHERE fact_id = ? RETURNING misses', [$factId])->fetchRow();
        return isset($row['misses']) && is_int($row['misses']) ? $row['misses'] : 0;
    }

    public function found(int $factId): void {
        $this->db->execute('UPDATE memory_fact_questions SET misses = 0 WHERE fact_id = ? AND misses > 0', [$factId]);
    }
}

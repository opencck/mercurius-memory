-- Self-check of search: the questions the model of sleep wrote to a fact without its words, for the version they were
-- written for, and how many nights with words the fact was still not found by them.
-- spec: brief/memory.md#самопроверка-поиска
CREATE TABLE memory_fact_questions (
    fact_id bigint PRIMARY KEY REFERENCES facts (id) ON DELETE CASCADE,
    version integer NOT NULL,
    questions jsonb NOT NULL,
    misses integer NOT NULL DEFAULT 0
);

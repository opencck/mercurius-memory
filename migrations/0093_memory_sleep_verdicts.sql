-- What the model of sleep said of a pair of facts, kept by a hash of the version of its prompt and of both texts: a pair
-- is not asked again while nothing changed. The facts are named so a forgotten one leaves the cache.
-- spec: brief/memory.md#судья-пары
CREATE TABLE memory_sleep_verdicts (
    key text PRIMARY KEY,
    fact_a bigint NOT NULL,
    fact_b bigint NOT NULL,
    label text NOT NULL,
    confidence real NOT NULL,
    newer text,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX memory_sleep_verdicts_a ON memory_sleep_verdicts (fact_a);
CREATE INDEX memory_sleep_verdicts_b ON memory_sleep_verdicts (fact_b);

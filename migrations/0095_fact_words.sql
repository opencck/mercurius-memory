-- Words a fact is found by besides its text: the keywords the agent gave and the words sleep added. They live beside
-- the text so a new rule of the analyzer keys them again with it; enriched says how far sleep went (0 — not yet,
-- 1 — words in the fact's language, 2 — also translations). The agent's keywords of facts written before are taken
-- from the latest accepted proposal that made or changed the fact.
-- spec: brief/memory.md#ночное-обогащение
ALTER TABLE facts
    ADD COLUMN words text[] NOT NULL DEFAULT '{}',
    ADD COLUMN enriched smallint NOT NULL DEFAULT 0;

UPDATE facts f SET words = p.keywords
FROM (
    SELECT DISTINCT ON (fact_id) fact_id, keywords
    FROM memory_proposals
    WHERE fact_id IS NOT NULL AND kind IN ('add', 'change') AND status = 'approved' AND keywords <> '{}'
    ORDER BY fact_id, id DESC
) p
WHERE p.fact_id = f.id;

CREATE INDEX facts_enriched ON facts (enriched, id) WHERE status = 'active';

-- Where a fact came from and the agent's keywords travel with its proposal; each version keeps who made it
-- (owner_said, owner_confirmed, agent, tool).
-- spec: BRIEF.md#факты
ALTER TABLE memory_proposals ADD COLUMN provenance text;
ALTER TABLE memory_proposals ADD COLUMN keywords text[] NOT NULL DEFAULT '{}';
ALTER TABLE fact_versions ADD COLUMN source text;

-- The owner's probes of memory: a question memory must always answer with its fact; the guard asks them every night.
-- spec: brief/memory.md#пробы-владельца
CREATE TABLE memory_probes (
    id bigserial PRIMARY KEY,
    question text NOT NULL,
    -- no reference: a fact that is gone is exactly what the probe tells the owner about
    fact_id bigint NOT NULL,
    -- the owner was told it is missing; told again only after it was found again
    missing boolean NOT NULL DEFAULT false,
    created_at timestamptz NOT NULL DEFAULT now()
);

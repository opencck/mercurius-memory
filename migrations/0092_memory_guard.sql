-- The guard against a loss of memory: a snapshot of the active facts every night and on command, with or without sleep;
-- its own journal, so a night without sleep is not a skipped check of memory.
-- spec: brief/memory.md#страж-потерь
CREATE TABLE memory_guard (
    id bigserial PRIMARY KEY,
    checked_at timestamptz NOT NULL DEFAULT now(),
    facts integer NOT NULL,
    chars integer NOT NULL,
    -- the owner was told of a loss on this check
    alerted boolean NOT NULL DEFAULT false
);

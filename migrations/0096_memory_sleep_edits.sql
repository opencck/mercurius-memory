-- Edits sleep made by itself under the owner's standing permission: which fact stayed (and the version the edit gave
-- it, for a merge), which one ended, and whether the owner undid it. Sleep ends a fact instead of forgetting it, so the
-- edit can be undone; the loss guard counts what sleep ended as its own.
-- spec: brief/memory.md#постоянное-разрешение
CREATE TABLE memory_sleep_edits (
    id bigserial PRIMARY KEY,
    -- copy | merge | conflict
    kind text NOT NULL,
    kept bigint REFERENCES facts (id) ON DELETE SET NULL,
    kept_version integer,
    ended bigint REFERENCES facts (id) ON DELETE SET NULL,
    ended_chars integer NOT NULL DEFAULT 0,
    created_at timestamptz NOT NULL DEFAULT now(),
    undone_at timestamptz
);

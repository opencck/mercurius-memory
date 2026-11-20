-- The owner's table of words that mean one thing («прокс» — «proxmox»): each group gives its words one key in search.
-- spec: brief/memory.md#словари
CREATE TABLE memory_aliases (
    id bigserial PRIMARY KEY,
    words text[] NOT NULL,
    added_by bigint,
    created_at timestamptz NOT NULL DEFAULT now()
);

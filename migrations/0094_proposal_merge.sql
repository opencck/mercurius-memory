-- A proposal to merge two facts: the kept fact is fact_id, the one to forget is merge_with; both versions are those the
-- owner was shown, and the merge is refused at decision time when either fact changed since. Forgetting the second fact
-- on acceptance must not take the proposal with it, so the link is cleared, not cascaded.
-- spec: brief/memory.md#слияние
ALTER TABLE memory_proposals
    ADD COLUMN merge_with bigint REFERENCES facts (id) ON DELETE SET NULL,
    ADD COLUMN fact_version integer,
    ADD COLUMN merge_version integer;

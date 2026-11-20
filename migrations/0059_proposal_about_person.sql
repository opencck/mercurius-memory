-- A proposal of a fact about another person: it goes only to the owner's memory, and the card says so.
-- spec: BRIEF.md#решения-по-доработкам-2026-11-18 — what is said about other people is kept only for the owner
ALTER TABLE memory_proposals ADD COLUMN about_person boolean NOT NULL DEFAULT false;

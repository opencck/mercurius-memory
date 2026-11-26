-- Why a fact given did not help (a short list) with a line of correction, and at which step of the selection each
-- candidate fell out (numbers of facts only, no text): input for the nightly check and the review of traces.
-- spec: brief/memory.md#журнал-выдачи-причины-и-срок
ALTER TABLE memory_recalls ADD COLUMN reason text;
ALTER TABLE memory_recalls ADD COLUMN note text;

CREATE TABLE memory_recall_stages (
    id bigserial PRIMARY KEY,
    conversation_id bigint NOT NULL REFERENCES conversations (id) ON DELETE CASCADE,
    run_id bigint REFERENCES runs (id) ON DELETE SET NULL,
    stages jsonb NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX memory_recall_stages_by_run ON memory_recall_stages (run_id) WHERE run_id IS NOT NULL;
CREATE INDEX memory_recalls_by_time ON memory_recalls (created_at);

-- the run takes the stages of its recall as it takes the recalls (0069)
CREATE OR REPLACE FUNCTION memory_recall_takes_run() RETURNS trigger AS $$
BEGIN
    UPDATE memory_recalls SET run_id = NEW.id
    WHERE run_id IS NULL AND conversation_id = NEW.conversation_id AND created_at > now() - interval '2 minutes';
    UPDATE memory_recall_stages SET run_id = NEW.id
    WHERE run_id IS NULL AND conversation_id = NEW.conversation_id AND created_at > now() - interval '2 minutes';
    RETURN NEW;
END
$$ LANGUAGE plpgsql;

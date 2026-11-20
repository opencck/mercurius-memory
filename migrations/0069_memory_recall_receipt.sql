-- A receipt of recall: the run that got the facts and the version of each it got. The recall is made before the run
-- starts, so the run takes the recalls of its conversation made in the last two minutes and not taken yet, as a
-- decision about the model does (0066).
-- spec: BRIEF.md#трассировка-и-метрики
ALTER TABLE memory_recalls ADD COLUMN run_id bigint REFERENCES runs (id) ON DELETE SET NULL;
ALTER TABLE memory_recalls ADD COLUMN version integer;
CREATE INDEX memory_recalls_by_run ON memory_recalls (run_id) WHERE run_id IS NOT NULL;

CREATE FUNCTION memory_recall_takes_run() RETURNS trigger AS $$
BEGIN
    UPDATE memory_recalls SET run_id = NEW.id
    WHERE run_id IS NULL AND conversation_id = NEW.conversation_id AND created_at > now() - interval '2 minutes';
    RETURN NEW;
END
$$ LANGUAGE plpgsql;

CREATE TRIGGER memory_recall_takes_run AFTER INSERT ON runs FOR EACH ROW EXECUTE FUNCTION memory_recall_takes_run();

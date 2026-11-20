-- How facts serve: the agent marks a fact it used as useful or not; trust moves, a fact under the threshold is not recalled.
-- spec: BRIEF.md#память-слои
-- ref: references/hermes-plugin-holographic/store.py@4a794f8:L694-L709 — helpful +0.05 and a count, unhelpful −0.10
ALTER TABLE facts ADD COLUMN helpful_count integer NOT NULL DEFAULT 0;

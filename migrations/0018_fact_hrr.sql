-- HRR-вектор факта: фазор (1024 косинуса, затем 1024 синуса), сходство — скалярное произведение / 1024.
-- spec: BRIEF.md#hrr-с-самого-начала

ALTER TABLE facts ADD COLUMN hrr vector(2048);

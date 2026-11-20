-- Ночная проверка памяти: журнал проходов со снимком (страж потерь) и паузы показа найденного владельцу.
-- spec: BRIEF.md#консолидация-dreaming

CREATE TABLE memory_consolidations (
    id bigserial PRIMARY KEY,
    started_at timestamptz NOT NULL DEFAULT now(),
    duration_ms integer NOT NULL DEFAULT 0,
    -- skipped — память не менялась, проверять нечего
    status text NOT NULL CHECK (status IN ('skipped', 'done', 'failed')),
    -- снимок для стража потерь: действующие факты и их символы
    facts integer NOT NULL DEFAULT 0,
    chars integer NOT NULL DEFAULT 0,
    -- вызовов модели; проверка — только код, всегда 0
    model_calls integer NOT NULL DEFAULT 0,
    report jsonb NOT NULL DEFAULT '[]'
);

CREATE INDEX memory_consolidations_by_time ON memory_consolidations (started_at DESC);

-- Что уже показано владельцу: дубликат, противоречие, неиспользуемый факт; повтор — после паузы.
CREATE TABLE memory_notices (
    kind text NOT NULL,
    a bigint NOT NULL,
    b bigint NOT NULL DEFAULT 0,
    shown_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (kind, a, b)
);

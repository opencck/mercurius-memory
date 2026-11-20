-- Память: факты с версиями и надгробиями, предложения владельцу, слой «всегда в контексте», журнал извлечений.
-- spec: BRIEF.md#факты
-- spec: BRIEF.md#фаза-6-по-частям

CREATE TABLE facts (
    id bigserial PRIMARY KEY,
    -- область видимости: owner | principal:<id> | chat:<id>
    scope text NOT NULL,
    -- знание или правило; правила фоновым задачам менять нельзя
    category text NOT NULL DEFAULT 'knowledge' CHECK (category IN ('knowledge', 'rule')),
    text text NOT NULL,
    -- ключи поиска: стемы и идентификаторы
    keys text[] NOT NULL,
    trust real NOT NULL DEFAULT 0.5,
    -- owner_said | owner_confirmed | agent | tool
    provenance text NOT NULL,
    status text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'ended')),
    version integer NOT NULL DEFAULT 1,
    run_id bigint REFERENCES runs (id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX facts_keys ON facts USING gin (keys) WHERE status = 'active';
CREATE INDEX facts_scope ON facts (scope, updated_at DESC) WHERE status = 'active';

CREATE TABLE fact_versions (
    fact_id bigint NOT NULL REFERENCES facts (id) ON DELETE CASCADE,
    version integer NOT NULL,
    text text NOT NULL,
    reason text NOT NULL,
    valid_from timestamptz NOT NULL DEFAULT now(),
    valid_to timestamptz,
    PRIMARY KEY (fact_id, version)
);

-- Забытое: отпечаток HMAC без текста, чтобы то же самое не вернулось
CREATE TABLE fact_tombstones (
    hmac text PRIMARY KEY,
    forgotten_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE memory_proposals (
    id bigserial PRIMARY KEY,
    -- add | change | end | forget | profile | summary
    kind text NOT NULL,
    fact_id bigint REFERENCES facts (id) ON DELETE CASCADE,
    scope text NOT NULL,
    text text NOT NULL,
    reason text NOT NULL,
    status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
    principal_id bigint REFERENCES principals (id) ON DELETE SET NULL,
    run_id bigint REFERENCES runs (id) ON DELETE SET NULL,
    card_chat_id text,
    card_message_id text,
    created_at timestamptz NOT NULL DEFAULT now(),
    decided_at timestamptz
);

CREATE INDEX memory_proposals_pending ON memory_proposals (id) WHERE status = 'pending';

-- Слой «всегда в контексте»: профиль владельца и рабочая сводка, с историей версий
CREATE TABLE memory_core (
    section text NOT NULL CHECK (section IN ('profile', 'summary')),
    version integer NOT NULL,
    text text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (section, version)
);

-- Какие факты уже подавались в беседе: окно неповтора
CREATE TABLE memory_recalls (
    id bigserial PRIMARY KEY,
    conversation_id bigint NOT NULL REFERENCES conversations (id) ON DELETE CASCADE,
    fact_id bigint NOT NULL REFERENCES facts (id) ON DELETE CASCADE,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX memory_recalls_by_conversation ON memory_recalls (conversation_id, id DESC);

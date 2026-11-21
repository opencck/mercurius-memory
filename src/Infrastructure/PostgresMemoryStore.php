<?php

declare(strict_types=1);

namespace Mercurius\Memory\Infrastructure;

use Amp\Postgres\PostgresLink;
use Mercurius\Memory\Domain\Fact;
use Mercurius\Memory\Domain\Hrr;
use Mercurius\Memory\Domain\MemoryStore;
use Mercurius\Memory\Domain\Proposal;
use Throwable;
use UnexpectedValueException;

use function Mercurius\Memory\t;

/**
 * spec: brief/memory.md#факты — facts with versions in PostgreSQL, keys in a GIN-indexed array
 * ref: references/hermes-plugin-holographic/history.py@4a794f8:L489-L710 (change_fact, end_fact, forget_fact, _erase)
 *      — versions and tombstones; here one database with a scope column instead of a file per scope
 */
final class PostgresMemoryStore implements MemoryStore {
    private const FACT = 'id, scope, category, text, array_to_json(keys)::text AS keys, trust::float8 AS trust, provenance, status, version, extract(epoch FROM updated_at)::bigint AS updated';
    private const PROPOSAL = 'id, kind, fact_id, scope, text, reason, status, principal_id, run_id, about_person, extract(epoch FROM created_at)::bigint AS created_epoch, provenance, array_to_json(keywords)::text AS keywords, merge_with, fact_version, merge_version';

    public function __construct(private readonly PostgresLink $db) {}

    public function addFact(string $scope, string $category, string $text, array $keys, float $trust, string $provenance, ?int $runId): Fact {
        $transaction = $this->db->beginTransaction();
        try {
            $row = $transaction->execute(
                'INSERT INTO facts (scope, category, text, keys, trust, provenance, run_id, hrr) VALUES (?, ?, ?, ?::text[], ?, ?, ?, ?::vector) RETURNING ' . self::FACT,
                [$scope, $category, $text, $keys, $trust, $provenance, $runId, self::vector($keys)],
            )->fetchRow() ?? throw new UnexpectedValueException('Fact was not created');
            $fact = self::fact_($row);
            $transaction->execute('INSERT INTO fact_versions (fact_id, version, text, reason, source) VALUES (?, 1, ?, ?, ?)', [$fact->id, $text, t('infrastructure.postgres_memory_store.dobavlen'), $provenance]);
            $transaction->commit();
            return $fact;
        } catch (Throwable $e) {
            if ($transaction->isActive()) {
                $transaction->rollback();
            }
            throw $e;
        }
    }

    public function fact(int $id): ?Fact {
        $row = $this->db->execute('SELECT ' . self::FACT . ' FROM facts WHERE id = ?', [$id])->fetchRow();
        return $row === null ? null : self::fact_($row);
    }

    public function similarities(array $factIds, array $phasor): array {
        if ($factIds === []) {
            return [];
        }
        $found = [];
        foreach ($this->db->execute(
            'SELECT id, (-(hrr <#> ?::vector) / ' . Hrr::DIM . ')::float8 AS sim FROM facts WHERE id = ANY(?::bigint[]) AND hrr IS NOT NULL',
            [self::literal($phasor), $factIds],
        ) as $row) {
            $found[self::int($row, 'id')] = isset($row['sim']) && is_float($row['sim']) ? $row['sim'] : 0.0;
        }
        return $found;
    }

    public function related(array $scopes, array $probes, float $threshold, int $limit): array {
        if ($scopes === [] || $probes === []) {
            return [];
        }
        $terms = [];
        $params = [];
        foreach ($probes as $probe) {
            $terms[] = '-(hrr <#> ?::vector)';
            $params[] = self::literal($probe);
        }
        $sim = 'GREATEST(' . implode(', ', $terms) . ') / ' . Hrr::DIM;
        $facts = [];
        foreach ($this->db->execute(
            'SELECT ' . self::FACT . ", sim FROM (SELECT *, $sim AS sim FROM facts WHERE status = 'active' AND scope = ANY(?::text[]) AND hrr IS NOT NULL) f
             WHERE sim >= ? ORDER BY sim DESC, id DESC LIMIT ?",
            [...$params, $scopes, $threshold, $limit],
        ) as $row) {
            $facts[] = self::fact_($row);
        }
        return $facts;
    }

    public function active(int $limit): array {
        $facts = [];
        foreach ($this->db->execute("SELECT " . self::FACT . " FROM facts WHERE status = 'active' ORDER BY id LIMIT ?", [$limit]) as $row) {
            $facts[] = self::fact_($row);
        }
        return $facts;
    }

    public function recalledAny(array $factIds): array {
        if ($factIds === []) {
            return [];
        }
        $found = [];
        foreach ($this->db->execute('SELECT DISTINCT fact_id FROM memory_recalls WHERE fact_id = ANY(?::bigint[])', [$factIds]) as $row) {
            $found[] = self::int($row, 'fact_id');
        }
        return $found;
    }

    public function rekey(\Closure $keys): int {
        $changed = 0;
        foreach ($this->db->query("SELECT id, text, array_to_json(keys)::text AS keys FROM facts WHERE status = 'active' ORDER BY id") as $row) {
            /** @var mixed $old */
            $old = json_decode(self::str($row, 'keys'), true, 4);
            $new = $keys(self::str($row, 'text'));
            if ($new === [] || $old === $new) {
                continue;
            }
            $this->db->execute('UPDATE facts SET keys = ?::text[], hrr = ?::vector WHERE id = ?', [$new, self::vector($new), self::int($row, 'id')]);
            $changed++;
        }
        return $changed;
    }

    public function encodeMissing(int $limit): int {
        $done = 0;
        foreach ($this->db->execute('SELECT id, array_to_json(keys)::text AS keys FROM facts WHERE hrr IS NULL ORDER BY id LIMIT ?', [$limit]) as $row) {
            /** @var mixed $keys */
            $keys = json_decode(self::str($row, 'keys'), true, 4, JSON_THROW_ON_ERROR);
            $list = [];
            if (is_array($keys)) {
                foreach (array_keys($keys) as $i) {
                    if (isset($keys[$i]) && is_string($keys[$i])) {
                        $list[] = $keys[$i];
                    }
                }
            }
            $this->db->execute('UPDATE facts SET hrr = ?::vector WHERE id = ? AND hrr IS NULL', [self::vector($list), self::int($row, 'id')]);
            $done++;
        }
        return $done;
    }

    /** @param list<string> $keys */
    private static function vector(array $keys): string {
        return self::literal(Hrr::phasor(Hrr::fact($keys)));
    }

    /** @param list<float> $values */
    private static function literal(array $values): string {
        return '[' . implode(',', array_map(static fn(float $v): string => sprintf('%.7F', $v), $values)) . ']';
    }

    public function candidates(array $scopes, array $keys, int $limit, array $weights = []): array {
        if ($scopes === [] || $keys === []) {
            return [];
        }
        $keys = array_values(array_unique($keys));
        $facts = [];
        foreach ($this->db->execute(
            "SELECT " . self::FACT . ", (SELECT coalesce(sum(w.weight), 0) FROM unnest(?::text[], ?::float8[]) AS w(key, weight) WHERE w.key = ANY(facts.keys)) AS score
             FROM facts WHERE status = 'active' AND scope = ANY(?::text[]) AND keys && ?::text[] ORDER BY score DESC, updated_at DESC, id DESC LIMIT ?",
            [$keys, array_map(static fn(string $k): float => $weights[$k] ?? 1.0, $keys), $scopes, $keys, $limit],
        ) as $row) {
            $facts[] = self::fact_($row);
        }
        return $facts;
    }

    public function frequencies(array $scopes, array $keys): array {
        $df = ['' => 0];
        if ($scopes === []) {
            return $df;
        }
        $total = $this->db->execute("SELECT count(*)::int AS n FROM facts WHERE status = 'active' AND scope = ANY(?::text[])", [$scopes])->fetchRow();
        $df[''] = self::int($total ?? [], 'n');
        if ($keys === []) {
            return $df;
        }
        foreach ($this->db->execute(
            "SELECT k AS key, count(*)::int AS n FROM facts, unnest(keys) AS k
             WHERE status = 'active' AND scope = ANY(?::text[]) AND k = ANY(?::text[]) GROUP BY k",
            [$scopes, $keys],
        ) as $row) {
            $df[self::str($row, 'key')] = self::int($row, 'n');
        }
        return $df;
    }

    public function recent(array $scopes, int $limit): array {
        if ($scopes === []) {
            return [];
        }
        $facts = [];
        foreach ($this->db->execute("SELECT " . self::FACT . " FROM facts WHERE status = 'active' AND scope = ANY(?::text[]) ORDER BY updated_at DESC, id DESC LIMIT ?", [$scopes, $limit]) as $row) {
            $facts[] = self::fact_($row);
        }
        return $facts;
    }

    public function faded(array $scopes, float $below, int $limit): array {
        $facts = [];
        foreach ($this->db->execute(
            "SELECT " . self::FACT . " FROM facts WHERE status = 'active' AND scope = ANY(?::text[]) AND trust < ? ORDER BY trust, id LIMIT ?",
            [$scopes, $below, max(1, $limit)],
        ) as $row) {
            $facts[] = self::fact_($row);
        }
        return $facts;
    }

    public function versions(int $factId): array {
        $rows = [];
        foreach ($this->db->execute('SELECT version, text, reason, source FROM fact_versions WHERE fact_id = ? ORDER BY version', [$factId]) as $row) {
            $source = $row['source'] ?? null;
            $rows[] = ['version' => self::int($row, 'version'), 'text' => self::str($row, 'text'), 'reason' => self::str($row, 'reason'), 'source' => is_string($source) ? $source : null];
        }
        return $rows;
    }

    public function changeFact(int $id, string $text, array $keys, string $reason, ?string $source = null): ?Fact {
        $transaction = $this->db->beginTransaction();
        try {
            $row = $transaction->execute(
                "UPDATE facts SET text = ?, keys = ?::text[], hrr = ?::vector, version = version + 1, updated_at = now() WHERE id = ? AND status = 'active' RETURNING " . self::FACT,
                [$text, $keys, self::vector($keys), $id],
            )->fetchRow();
            if ($row === null) {
                $transaction->rollback();
                return null;
            }
            $fact = self::fact_($row);
            $transaction->execute('UPDATE fact_versions SET valid_to = now() WHERE fact_id = ? AND valid_to IS NULL', [$id]);
            $transaction->execute('INSERT INTO fact_versions (fact_id, version, text, reason, source) VALUES (?, ?, ?, ?, ?)', [$id, $fact->version, $text, $reason, $source]);
            $transaction->commit();
            return $fact;
        } catch (Throwable $e) {
            if ($transaction->isActive()) {
                $transaction->rollback();
            }
            throw $e;
        }
    }

    public function rateFact(int $id, float $delta, bool $helpful): ?float {
        // ref: references/hermes-plugin-holographic/store.py@4a794f8:L694-L709 (record_feedback) — updated_at stays
        $row = $this->db->execute(
            "UPDATE facts SET trust = least(1, greatest(0, trust + ?)), helpful_count = helpful_count + ? WHERE id = ? AND status = 'active' RETURNING trust::float8 AS trust",
            [$delta, $helpful ? 1 : 0, $id],
        )->fetchRow();
        $trust = $row['trust'] ?? null;
        return is_float($trust) ? $trust : null;
    }

    public function endFact(int $id, string $reason): bool {
        $row = $this->db->execute("UPDATE facts SET status = 'ended', updated_at = now() WHERE id = ? AND status = 'active' RETURNING id", [$id])->fetchRow();
        if ($row === null) {
            return false;
        }
        $this->db->execute('UPDATE fact_versions SET valid_to = now(), reason = reason || ?::text WHERE fact_id = ? AND valid_to IS NULL', [t('infrastructure.postgres_memory_store.zavershen') . $reason, $id]);
        return true;
    }

    public function forgetFact(int $id, string $fingerprint): bool {
        $transaction = $this->db->beginTransaction();
        try {
            // Versions, recalls and tied proposals go with the fact (ON DELETE CASCADE); proposals that added the same
            // text and were never tied go too. Only the fingerprint stays.
            $transaction->execute(
                "DELETE FROM memory_proposals WHERE fact_id IS NULL AND kind = 'add' AND text = (SELECT text FROM facts WHERE id = ?)",
                [$id],
            );
            $row = $transaction->execute('DELETE FROM facts WHERE id = ? RETURNING id', [$id])->fetchRow();
            if ($row === null) {
                $transaction->rollback();
                return false;
            }
            $transaction->execute('INSERT INTO fact_tombstones (hmac) VALUES (?) ON CONFLICT DO NOTHING', [$fingerprint]);
            $transaction->commit();
            return true;
        } catch (Throwable $e) {
            if ($transaction->isActive()) {
                $transaction->rollback();
            }
            throw $e;
        }
    }

    public function forgotten(string $fingerprint): bool {
        return $this->db->execute('SELECT 1 AS found FROM fact_tombstones WHERE hmac = ?', [$fingerprint])->fetchRow() !== null;
    }

    public function propose(string $kind, ?int $factId, string $scope, string $text, string $reason, ?int $principalId, ?int $runId, bool $aboutPerson = false, ?string $provenance = null, array $keywords = [], ?int $mergeWith = null, ?int $factVersion = null, ?int $mergeVersion = null): Proposal {
        $row = $this->db->execute(
            'INSERT INTO memory_proposals (kind, fact_id, scope, text, reason, principal_id, run_id, about_person, provenance, keywords, merge_with, fact_version, merge_version) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?::text[], ?, ?, ?) RETURNING ' . self::PROPOSAL,
            [$kind, $factId, $scope, $text, $reason, $principalId, $runId, $aboutPerson, $provenance, $keywords, $mergeWith, $factVersion, $mergeVersion],
        )->fetchRow() ?? throw new UnexpectedValueException('Proposal was not created');
        return self::proposal_($row);
    }

    public function proposal(int $id): ?Proposal {
        $row = $this->db->execute('SELECT ' . self::PROPOSAL . ' FROM memory_proposals WHERE id = ?', [$id])->fetchRow();
        return $row === null ? null : self::proposal_($row);
    }

    public function decide(int $id, string $status): ?Proposal {
        $row = $this->db->execute(
            "UPDATE memory_proposals SET status = ?, decided_at = now() WHERE id = ? AND status = 'pending' RETURNING " . self::PROPOSAL,
            [$status, $id],
        )->fetchRow();
        return $row === null ? null : self::proposal_($row);
    }

    public function setCard(int $id, string $chatId, string $messageId): void {
        $this->db->execute('UPDATE memory_proposals SET card_chat_id = ?, card_message_id = ? WHERE id = ?', [$chatId, $messageId, $id]);
    }

    public function linkProposal(int $proposalId, int $factId): void {
        $this->db->execute('UPDATE memory_proposals SET fact_id = ? WHERE id = ?', [$factId, $proposalId]);
    }

    public function cards(int $factId): array {
        $cards = [];
        foreach ($this->db->execute(
            "SELECT card_chat_id, card_message_id FROM memory_proposals
             WHERE card_message_id IS NOT NULL AND (fact_id = ? OR merge_with = ? OR (fact_id IS NULL AND kind = 'add' AND text = (SELECT text FROM facts WHERE id = ?)))",
            [$factId, $factId, $factId],
        ) as $row) {
            $cards[] = [self::str($row, 'card_chat_id'), self::str($row, 'card_message_id')];
        }
        return $cards;
    }

    public function reopen(int $id): void {
        $this->db->execute("UPDATE memory_proposals SET status = 'pending', decided_at = NULL WHERE id = ? AND status = 'approved'", [$id]);
    }

    public function pending(): array {
        $proposals = [];
        foreach ($this->db->query("SELECT " . self::PROPOSAL . " FROM memory_proposals WHERE status = 'pending' ORDER BY id") as $row) {
            $proposals[] = self::proposal_($row);
        }
        return $proposals;
    }

    public function core(string $section): string {
        $row = $this->db->execute('SELECT text FROM memory_core WHERE section = ? ORDER BY version DESC LIMIT 1', [$section])->fetchRow();
        return $row === null ? '' : self::str($row, 'text');
    }

    public function setCore(string $section, string $text): void {
        $transaction = $this->db->beginTransaction();
        try {
            // Two quick accepts would both read the same max(version): one writer at a time.
            $transaction->execute('SELECT pg_advisory_xact_lock(hashtext(?)) AS locked', ['memory_core']);
            $transaction->execute(
                'INSERT INTO memory_core (section, version, text) SELECT ?, coalesce(max(version), 0) + 1, ? FROM memory_core WHERE section = ?',
                [$section, $text, $section],
            );
            $transaction->commit();
        } catch (Throwable $e) {
            if ($transaction->isActive()) {
                $transaction->rollback();
            }
            throw $e;
        }
    }

    public function markRecalled(int $conversationId, array $factIds, array $versions = []): void {
        foreach ($factIds as $id) {
            $this->db->execute('INSERT INTO memory_recalls (conversation_id, fact_id, version) VALUES (?, ?, ?)', [$conversationId, $id, $versions[$id] ?? null]);
        }
    }

    public function receipt(int $runId): array {
        $rows = [];
        foreach ($this->db->execute('SELECT fact_id, version, helpful FROM memory_recalls WHERE run_id = ? ORDER BY id', [$runId]) as $row) {
            $version = $row['version'] ?? null;
            $helpful = $row['helpful'] ?? null;
            $rows[] = ['fact' => self::int($row, 'fact_id'), 'version' => is_int($version) ? $version : null, 'helpful' => is_bool($helpful) ? $helpful : null];
        }
        return $rows;
    }

    public function markHelpful(int $runId, int $factId, bool $helpful): bool {
        $row = $this->db->execute(
            'UPDATE memory_recalls SET helpful = ? WHERE id = (SELECT id FROM memory_recalls WHERE run_id = ? AND fact_id = ? AND helpful IS NULL ORDER BY id LIMIT 1) RETURNING id',
            [$helpful, $runId, $factId],
        )->fetchRow();
        return $row !== null;
    }

    public function usage(int $factId): array {
        $row = $this->db->execute(
            'SELECT count(*) AS recalled, count(*) FILTER (WHERE helpful) AS helpful, count(*) FILTER (WHERE NOT helpful) AS unhelpful FROM memory_recalls WHERE fact_id = ?',
            [$factId],
        )->fetchRow() ?? [];
        return ['recalled' => self::int($row, 'recalled'), 'helpful' => self::int($row, 'helpful'), 'unhelpful' => self::int($row, 'unhelpful')];
    }

    public function recalled(int $conversationId, int $window): array {
        $ids = [];
        foreach ($this->db->execute('SELECT fact_id FROM memory_recalls WHERE conversation_id = ? ORDER BY id DESC LIMIT ?', [$conversationId, $window]) as $row) {
            $ids[self::int($row, 'fact_id')] = true;
        }
        return array_keys($ids);
    }

    /** @param array<string, mixed> $row */
    private static function fact_(array $row): Fact {
        /** @var mixed $keys */
        $keys = json_decode(self::str($row, 'keys'), true, 4, JSON_THROW_ON_ERROR);
        $list = [];
        if (is_array($keys)) {
            foreach (array_keys($keys) as $i) {
                if (isset($keys[$i]) && is_string($keys[$i])) {
                    $list[] = $keys[$i];
                }
            }
        }
        $trust = isset($row['trust']) && is_float($row['trust']) ? $row['trust'] : 0.0;
        return new Fact(
            self::int($row, 'id'),
            self::str($row, 'scope'),
            self::str($row, 'category'),
            self::str($row, 'text'),
            $list,
            $trust,
            self::str($row, 'provenance'),
            self::str($row, 'status') === 'active',
            self::int($row, 'version'),
            self::int($row, 'updated'),
        );
    }

    /** @param array<string, mixed> $row */
    private static function proposal_(array $row): Proposal {
        return new Proposal(
            self::int($row, 'id'),
            self::str($row, 'kind'),
            isset($row['fact_id']) ? self::int($row, 'fact_id') : null,
            self::str($row, 'scope'),
            self::str($row, 'text'),
            self::str($row, 'reason'),
            self::str($row, 'status'),
            isset($row['principal_id']) ? self::int($row, 'principal_id') : null,
            isset($row['run_id']) ? self::int($row, 'run_id') : null,
            ($row['about_person'] ?? false) === true,
            isset($row['created_epoch']) && is_int($row['created_epoch']) ? $row['created_epoch'] : null,
            isset($row['provenance']) && is_string($row['provenance']) ? $row['provenance'] : null,
            self::words($row['keywords'] ?? null),
            isset($row['merge_with']) ? self::int($row, 'merge_with') : null,
            isset($row['fact_version']) ? self::int($row, 'fact_version') : null,
            isset($row['merge_version']) ? self::int($row, 'merge_version') : null,
        );
    }

    /** @return list<string> */
    private static function words(mixed $json): array {
        if (!is_string($json)) {
            return [];
        }
        /** @var mixed $list */
        $list = json_decode($json, true, 4);
        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $key): int {
        $value = $row[$key] ?? null;
        if (!is_int($value)) {
            throw new UnexpectedValueException(sprintf('Malformed memory row: %s', $key));
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key): string {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new UnexpectedValueException(sprintf('Malformed memory row: %s', $key));
        }
        return $value;
    }
}

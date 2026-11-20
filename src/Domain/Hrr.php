<?php

declare(strict_types=1);

namespace Mercurius\Memory\Domain;

/**
 * Holographic reduced representations over phases: every word is a vector of DIM angles derived from SHA-256, so
 * nothing about words is stored; a fact keeps one vector. Binding adds phases, a bundle is the circular mean,
 * similarity is the mean cosine of the phase differences.
 *
 * In PostgreSQL a vector is kept as its phasor — DIM cosines, then DIM sines — so that the similarity of two vectors
 * is their inner product divided by DIM, one pgvector operation.
 *
 * spec: BRIEF.md#hrr-с-самого-начала
 * ref: references/hermes-plugin-holographic/holographic.py@4a794f8:L106-L152 (encode_atom, bind, bundle, similarity,
 *      encode_text, encode_fact) — the same atoms and roles; the text is our keys (stems and identifiers), not raw
 *      words, so word forms meet; the entities are the identifiers among the keys
 */
final class Hrr {
    public const DIM = 1024;
    /** below it a probe is noise: measured by holographic at DIM 1024 (retrieval.py:L191-L195) */
    public const RELATED_THRESHOLD = 0.2;
    public const ROLE_CONTENT = '__hrr_role_content__';
    public const ROLE_ENTITY = '__hrr_role_entity__';
    private const EMPTY = '__hrr_empty__';
    private const CACHE = 4096;

    /** @var array<string, list<float>> */
    private static array $atoms = [];

    /** @return list<float> DIM phases in [0, 2π) */
    public static function atom(string $word): array {
        if (isset(self::$atoms[$word])) {
            return self::$atoms[$word];
        }
        $phases = [];
        $scale = 2.0 * M_PI / 65536.0;
        for ($i = 0; $i < intdiv(self::DIM, 16); $i++) {
            $values = unpack('v16', hash('sha256', $word . ':' . $i, true));
            $values = $values === false ? [] : $values;
            foreach (array_keys($values) as $k) {
                $phases[] = (float) (is_int($values[$k]) ? $values[$k] : 0) * $scale;
            }
        }
        if (count(self::$atoms) >= self::CACHE) {
            self::$atoms = [];
        }
        return self::$atoms[$word] = $phases;
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     * @return list<float>
     */
    public static function bind(array $a, array $b): array {
        $out = [];
        foreach ($a as $i => $phase) {
            $out[] = fmod($phase + $b[$i], 2.0 * M_PI);
        }
        return $out;
    }

    /**
     * @param non-empty-list<list<float>> $vectors
     * @return list<float>
     */
    public static function bundle(array $vectors): array {
        $out = [];
        for ($d = 0; $d < self::DIM; $d++) {
            $cos = 0.0;
            $sin = 0.0;
            foreach ($vectors as $vector) {
                $cos += cos($vector[$d]);
                $sin += sin($vector[$d]);
            }
            $angle = atan2($sin, $cos);
            $out[] = $angle < 0 ? $angle + 2.0 * M_PI : $angle;
        }
        return $out;
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    public static function similarity(array $a, array $b): float {
        $sum = 0.0;
        foreach ($a as $i => $phase) {
            $sum += cos($phase - $b[$i]);
        }
        return $sum / (float) self::DIM;
    }

    /**
     * @param list<string> $keys
     * @return list<float>
     */
    public static function text(array $keys): array {
        return $keys === [] ? self::atom(self::EMPTY) : self::bundle(array_map(self::atom(...), $keys));
    }

    /**
     * A fact: its keys bound to the content role, and each identifier among them bound to the entity role.
     *
     * @param list<string> $keys
     * @return list<float>
     */
    public static function fact(array $keys): array {
        $parts = [self::bind(self::text($keys), self::atom(self::ROLE_CONTENT))];
        foreach ($keys as $key) {
            if (TextAnalyzer::isIdentifier($key)) {
                $parts[] = self::bind(self::atom($key), self::atom(self::ROLE_ENTITY));
            }
        }
        return self::bundle($parts);
    }

    /**
     * A message in the shape its words take inside a fact.
     *
     * @param list<string> $keys
     * @return list<float>
     */
    public static function query(array $keys): array {
        return self::bind(self::text($keys), self::atom(self::ROLE_CONTENT));
    }

    /**
     * Probes for "what is tied to this key": as an entity and as a word of the content. similarity(fact, probe) is
     * similarity(unbind(fact, key), role) — holographic's related() — as one inner product.
     *
     * @return array{list<float>, list<float>}
     */
    public static function probes(string $key): array {
        $atom = self::atom($key);
        return [self::bind($atom, self::atom(self::ROLE_ENTITY)), self::bind($atom, self::atom(self::ROLE_CONTENT))];
    }

    /**
     * @param list<float> $phases
     * @return list<float> DIM cosines then DIM sines
     */
    public static function phasor(array $phases): array {
        return [...array_map(cos(...), $phases), ...array_map(sin(...), $phases)];
    }

    /**
     * Similarity of two phasors: the inner product over DIM.
     *
     * @param list<float> $a
     * @param list<float> $b
     */
    public static function dot(array $a, array $b): float {
        $sum = 0.0;
        foreach ($a as $i => $value) {
            $sum += $value * $b[$i];
        }
        return $sum / (float) self::DIM;
    }
}

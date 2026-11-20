<?php

declare(strict_types=1);

namespace Mercurius\Memory;

/**
 * The SQL migrations of the memory's tables (PostgreSQL with pgvector), numbered in one sequence with the application
 * that uses them: the application applies them together with its own, by number.
 */
final class Migrations {
    public static function directory(): string {
        return dirname(__DIR__) . '/migrations';
    }
}

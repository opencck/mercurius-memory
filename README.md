# mercurius-memory

**English** | [Русский](README.ru.md)

Memory for AMPHP agents on PostgreSQL: facts with versions, trust and provenance; recall by stemmed keys, words of one
root and HRR vectors; proposals of new or changed facts that the owner decides; a core layer always in context; a
nightly consolidation that finds duplicates, contradictions and facts that stopped helping.

Part of [Mercurius](https://github.com/opencck/mercurius) — an autonomous personal agent. The package does not depend on the Mercurius application and can be used on its own.

**Status:** used by Mercurius.

**Stack:** PHP 8.2+, AMPHP v3, `amphp/postgres`, PostgreSQL 17 with `pgvector`, `wamania/php-stemmer`.

## Layout

| Path | Contents |
|------|----------|
| `src/Domain/` | `Fact`, `Proposal`, `MemoryStore` (the narrow interface of storage), `TextAnalyzer` (keys of a text), `Hrr`, `ContentScreen` (what may not become a fact) |
| `src/App/` | `Recall`, `Proposals`, `CoreEntries`, `Consolidation`, `Corroboration`, `MemoryContext`, `Audience` |
| `src/Infrastructure/` | `PostgresMemoryStore`, `PostgresConsolidationJournal`; `InMemoryMemoryStore` and `InMemoryConsolidationJournal` for tests and small uses |
| `src/Testing/MemoryStoreContract.php` | One contract test for every `MemoryStore`: extend it with your store |
| `migrations/` | The SQL of the memory's tables; `Migrations::directory()` gives the folder |
| `resources/` | English texts; dictionaries: Russian words of one root (DerivBase.Ru, Apache 2.0), Traditional to Simplified Chinese characters (OpenCC, Apache 2.0), Chinese words (jieba, MIT) |

## Use

Apply the migrations of `Migrations::directory()` together with your own (they are numbered in one sequence with the
application that uses them), then:

```php
use Mercurius\Memory\Domain\TextAnalyzer;
use Mercurius\Memory\Infrastructure\PostgresMemoryStore;

$store = new PostgresMemoryStore($postgres);
$keys = (new TextAnalyzer())->keys('The owner drinks tea without sugar');
$fact = $store->addFact('owner', 'knowledge', 'The owner drinks tea without sugar', $keys, 0.8, 'owner_said', null);
```

Texts are English; give your own once: `Mercurius\Memory\Texts::translateWith(fn(string $key): string => ...)`.

## Checks

`composer check` — PHPUnit, PHPStan (level max) and Psalm (level 1). The PostgreSQL store is checked by the
application against a real database (`MemoryStoreContract`).

## License

[MIT](LICENSE). The dictionaries in `resources/dictionaries/` are under their own licences (Apache 2.0, MIT), the licence files lie beside them.

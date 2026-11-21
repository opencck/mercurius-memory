# mercurius-memory

[English](README.md) | **Русский** | [中文](README.cn.md)

Память для агентов на AMPHP и PostgreSQL: факты с версиями, доверием и происхождением; вспоминание по ключам стемов,
однокоренным словам и векторам HRR; предложения новых и изменённых фактов, которые решает владелец; слой ядра всегда в
контексте; ночная проверка, которая находит повторы, противоречия и факты, переставшие помогать.

Часть проекта [Меркурий (Mercurius)](https://github.com/opencck/mercurius) — автономного персонального агента. Пакет не зависит от приложения Меркурия и может использоваться отдельно.

**Статус:** используется Меркурием.

**Стек:** PHP 8.2+, AMPHP v3, `amphp/postgres`, PostgreSQL 17 с `pgvector`, `wamania/php-stemmer`.

## Устройство

| Путь | Что там |
|------|---------|
| `src/Domain/` | `Fact`, `Proposal`, `MemoryStore` (узкий интерфейс хранилища), `TextAnalyzer` (ключи текста), `Hrr`, `ContentScreen` (что не может стать фактом) |
| `src/App/` | `Recall`, `Proposals`, `CoreEntries`, `Consolidation`, `Corroboration`, `MemoryContext`, `Audience`, `Aliases` (таблица соответствий слов владельца) |
| `src/Infrastructure/` | `PostgresMemoryStore`, `PostgresConsolidationJournal`, `PostgresAliasStore`; `InMemoryMemoryStore`, `InMemoryConsolidationJournal` и `InMemoryAliasStore` — для тестов и простых случаев |
| `src/Testing/MemoryStoreContract.php` | Один контрактный тест для любого `MemoryStore`: унаследуйте его своим хранилищем |
| `migrations/` | SQL таблиц памяти; каталог — `Migrations::directory()` |
| `resources/` | Тексты на английском; словари: однокоренные русские слова (DerivBase.Ru, Apache 2.0), традиционные иероглифы в упрощённые (OpenCC, Apache 2.0), китайские слова (jieba, MIT), заимствования на трёх языках |

## Использование

Миграции из `Migrations::directory()` применяются вместе со своими (нумерация общая с приложением, которое их
использует), затем:

```php
use Mercurius\Memory\Domain\TextAnalyzer;
use Mercurius\Memory\Infrastructure\PostgresMemoryStore;

$store = new PostgresMemoryStore($postgres);
$keys = (new TextAnalyzer())->keys('Владелец пьёт чай без сахара');
$fact = $store->addFact('owner', 'knowledge', 'Владелец пьёт чай без сахара', $keys, 0.8, 'owner_said', null);
```

Тексты — на английском; свои задаются один раз: `Mercurius\Memory\Texts::translateWith(fn(string $key): string => ...)`.

## Проверки

`composer check` — PHPUnit, PHPStan (уровень max) и Psalm (уровень 1). Хранилище PostgreSQL проверяет приложение на
настоящей базе (`MemoryStoreContract`).

## Лицензия

[MIT](LICENSE). Словари в `resources/dictionaries/` — под своими лицензиями (Apache 2.0, MIT), файлы лицензий лежат рядом с ними.

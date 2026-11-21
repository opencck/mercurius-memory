# mercurius-memory

[English](README.md) | [Русский](README.ru.md) | **中文**

> 本文由模型翻译，尚未经人工审校；如有出入，以英文版为准。

面向 AMPHP 智能体、基于 PostgreSQL 的记忆：带版本、可信度和来源的事实；按词干键、同根词和 HRR 向量召回；由所有者裁决的新增或修改事实的提案；始终在上下文中的核心层；以及每晚的整合，用于找出重复、矛盾和已不再有用的事实。

属于 [Mercurius](https://github.com/opencck/mercurius)——一个自主的个人智能体。该软件包不依赖 Mercurius 应用，可以单独使用。

**状态：** 已被 Mercurius 使用。

**技术栈：** PHP 8.2+、AMPHP v3、`amphp/postgres`、带 `pgvector` 的 PostgreSQL 17、`wamania/php-stemmer`。

## 结构

| 路径 | 内容 |
|------|------|
| `src/Domain/` | `Fact`、`Proposal`、`MemoryStore`（存储的窄接口）、`TextAnalyzer`（文本的键）、`Hrr`、`ContentScreen`（哪些内容不得成为事实） |
| `src/App/` | `Recall`、`Proposals`、`CoreEntries`、`Consolidation`、`Corroboration`、`MemoryContext`、`Audience`、`Aliases`（所有者的同义词表，表中的词表示同一事物） |
| `src/Infrastructure/` | `PostgresMemoryStore`、`PostgresConsolidationJournal`、`PostgresAliasStore`；供测试和小规模使用的 `InMemoryMemoryStore`、`InMemoryConsolidationJournal` 和 `InMemoryAliasStore` |
| `src/Testing/MemoryStoreContract.php` | 适用于所有 `MemoryStore` 的统一契约测试：用你的存储扩展它 |
| `migrations/` | 记忆表的 SQL；`Migrations::directory()` 返回该文件夹 |
| `resources/` | 英文文本；词典：俄语同根词（DerivBase.Ru，Apache 2.0）、繁体到简体汉字（OpenCC，Apache 2.0）、汉语词（jieba，MIT）、三种语言的外来词 |

## 使用

将 `Migrations::directory()` 的迁移与你自己的迁移一起应用（它们与使用它们的应用共用同一个编号序列），然后：

```php
use Mercurius\Memory\Domain\TextAnalyzer;
use Mercurius\Memory\Infrastructure\PostgresMemoryStore;

$store = new PostgresMemoryStore($postgres);
$keys = (new TextAnalyzer())->keys('The owner drinks tea without sugar');
$fact = $store->addFact('owner', 'knowledge', 'The owner drinks tea without sugar', $keys, 0.8, 'owner_said', null);
```

文本为英文；可一次性提供你自己的：`Mercurius\Memory\Texts::translateWith(fn(string $key): string => ...)`。

## 检查

`composer check` —— PHPUnit、PHPStan（level max）和 Psalm（level 1）。PostgreSQL 存储由应用针对真实数据库进行检查（`MemoryStoreContract`）。

## 许可证

[MIT](LICENSE)。`resources/dictionaries/` 中的词典适用各自的许可证（Apache 2.0、MIT），许可证文件就放在它们旁边。

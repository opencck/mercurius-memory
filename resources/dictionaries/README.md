# Dictionaries

| File | What | Source and licence |
|------|------|--------------------|
| `ru_derivation.tsv.gz` | Pairs of Snowball stems of Russian words of one root («ноч» — «ночн»), one pair per line, `#` lines are comments | DerivBase.Ru in UDer 1.1, Apache-2.0 ([licence](DerivBase.Ru-Apache-2.0.txt)), https://github.com/s231644/DerivBaseRu; pairs as built by hermes-plugin-holographic 4a794f8 (`dictionaries/ru_derivation.tsv.gz`, direct parent-child links whose shape is a derivation) |

Used by `OpenCCK\Memory\Domain\TextAnalyzer::related()`: a same-root word helps recall a fact at 0.6 of an exact
match and never recalls one alone (`BRIEF.md#анализ-текста`).

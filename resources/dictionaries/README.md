# Dictionaries

| File | What | Source and licence |
|------|------|--------------------|
| `ru_derivation.tsv.gz` | Pairs of Snowball stems of Russian words of one root («ноч» — «ночн»), one pair per line, `#` lines are comments | DerivBase.Ru in UDer 1.1, Apache-2.0 ([licence](DerivBase.Ru-Apache-2.0.txt)), https://github.com/s231644/DerivBaseRu; pairs as built by hermes-plugin-holographic 4a794f8 (`dictionaries/ru_derivation.tsv.gz`, direct parent-child links whose shape is a derivation) |

| `zh_fold.txt` | Traditional Chinese characters (first line) and their Simplified forms (second line), character by character | OpenCC ver.1.4.2 `TSCharacters.txt`, Apache-2.0 ([licence](OpenCC-Apache-2.0.txt)), https://github.com/BYVoid/OpenCC; single-character entries as cut by hermes-plugin-holographic 4a794f8 (`dictionaries/cjk_fold.py`) |
| `loanwords.txt` | Words that are one word in several spellings and languages (Latin, Cyrillic, Chinese), a group per line | the first 55 groups from hermes-plugin-holographic 4a794f8 (`text.py` `_LOANWORDS`, MIT); the rest written for Mercurius |
| `zh_words.txt.gz` | Chinese words and their frequencies, folded to Simplified: one line per first character (format in its header) | jieba's `dict.txt`, MIT ([licence](jieba-MIT.txt)), https://github.com/fxsjy/jieba; as built by hermes-plugin-holographic 4a794f8 (`dictionaries/zh_words.txt.gz`) |

`ru_derivation.tsv.gz` is used by `Mercurius\Memory\Domain\TextAnalyzer::related()`: a same-root word helps recall a
fact at 0.6 of an exact match and never recalls one alone (`brief/memory.md#ключи-и-однокоренные` of the main repository).
The Chinese files are used by `Mercurius\Memory\Domain\Chinese` (`brief/memory.md#китайский-язык`).

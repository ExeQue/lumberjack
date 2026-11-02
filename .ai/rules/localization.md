---
paths:
    - 'config/app.php'
    - 'lang/**'
    - 'resources/js/**'
---

# Localization

## Fixed strings are never inline

UI text lives in `lang/{locale}/{concern}.php`, nested by sub-concern and grouped by the screen it appears on. Where a string is a button rather than a heading, or carries a length limit, say so in a comment above it.

## English is the key of record

Every locale carries the keys `lang/en` carries, and the fallback locale is `en`. A key missing from a locale falls through to English silently - a correct English sentence in the middle of a Danish page that no test flags. Add a string to `lang/en` first, then to every other locale in the same change.

## Write it the way the language writes it

- Capitalisation follows the language: English sentence case for labels and buttons.
- Compounds follow the language: Danish writes compounds as one word or hyphenated, never as an English-style open compound.
- Politeness follows the language: Danish "venligst" is officialese; the imperative alone is polite enough.
- Pluralisation uses the pipe form, `'{1} 1 issue|[2,*] :count issues'`. Never build a plural by concatenating strings.
- Placeholders (`:count`, `:project`) are not translated and may move to wherever the sentence needs them.

## No em or en dashes, anywhere

Never write an em dash or an en dash - only the plain hyphen. That holds for translations, views, markdown, comments, commit messages and strings in code and config, a separator between two dates included.

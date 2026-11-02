---
paths:
    - 'app/Domains/*/Enums/**'
---

# Enums

## Enums are hand-written backed enums

In `{Domain}/Enums/`, never `artisan make:enum`, cast through the model's `casts()`.

## Enum labels come from translation keys

Enums expose human-facing text through `label()` / `description()` methods that return a translation built from the case value, e.g. `__('issues.status.'.$this->value)`. Never hardcode display strings in the enum. Non-translatable per-case data uses a `match ($this)` expression or `config()`.

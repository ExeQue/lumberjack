---
paths:
    - 'app/**'
---

# App

## Every PHP file declares strict types

`declare(strict_types=1);` directly after `<?php`. Pint enforces it (`declare_strict_types` in `pint.json`).

## Mark overridden methods with #[Override]

Any method that overrides a parent class or implements an interface method carries the `#[Override]` attribute, imported as `use Override;`.

## Dates are immutable

`Date::use(CarbonImmutable::class)` is set globally, so `now()`, `today()` and model date casts all return `CarbonImmutable`. Mutating calls like `->addDay()` return a new instance instead of changing the receiver - assign the result. Build dates with the `now()` / `today()` helpers; use `Carbon::parse()` only when parsing external input.

## Never iterate with each() when the body changes what the query matches

`each()` and `chunk()` page by OFFSET. The second page asks for rows 1000-1999 of a query that is re-run from scratch, so if the body deleted or updated the rows the WHERE clause selects, every remaining row has shifted up by however many left the result set - and the loop skips them in silence. Nothing errors, and the caller is told it finished.

Use `eachById()` (or `chunkById()`) whenever the callback writes anything the query filters on: a delete (retention cleanup), a status transition, or the very row the body creates. It pages by ascending primary key, so rows leaving the set cannot move the ones that are left. `each()` is still right for a read-only pass, and for a write to a column no part of the query mentions.

A test only catches this above the chunk size - 1001 rows minimum. One page never exhibits it.

## Bound in-memory caches

Anything that remembers values per key in memory for a request, job or command (a scoped service, an Action property, a static) holds them in a fixed-capacity LRU structure in `app/Domains/Shared/Support/`, never a bare array keyed by project, issue or id. An ingest worker walks thousands of keys and would otherwise keep them all. Not needed for a single value that cannot grow. For values shared between requests use the cache store.

## Cast cache reads to the declared type

A method with a strict `int`/`float` return type that reads from the cache casts explicitly: `(int) Cache::get($key, 0)`. The array driver used in tests preserves PHP types; Redis returns strings, so the code passes tests and throws `TypeError` in production.

---
paths:
    - 'database/migrations/**'
---

# Migrations

## Foreign keys use foreignIdFor

Always `$table->foreignIdFor(Model::class)->constrained()->cascadeOnDelete()` (or `nullOnDelete()`), never a manual `unsignedBigInteger` plus `foreign()`.

## UUID is the public identifier, auto-increment id stays the primary key

Tables whose model is exposed in URLs get `$table->id()` plus a separate `$table->uuid()->unique();` column. The uuid is never the primary key.

## Migrations query tables without global scopes

A migration runs against the schema of its day while a model's global scopes target today's, so Eloquent in an older migration breaks `migrate:fresh` the day a scope is added. Use `DB::table(...)` (or `Model::withoutGlobalScopes()`) in data migrations.

## Dump the schema from migrations, never from a loaded dump

When the schema is dumped, regenerate it from scratch so the dump matches what the migrations build:

```bash
php artisan migrate:fresh --schema-path="."
php artisan schema:dump
```

Check the diff before committing: a batch number that moved in the `migrations` rows is not a schema change.

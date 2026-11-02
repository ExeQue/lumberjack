---
paths:
    - 'app/Domains/*/Models/**'
---

# Models

## UUID is the public identifier, auto-increment id stays the primary key

Models exposed in URLs (or MCP tool arguments) keep the auto-increment `id` as primary key and add a separate `uuid` column. Use `HasUuids` together with `uniqueIds(): array { return ['uuid']; }` so only the uuid column is filled. Never let the uuid become the primary key. Routes bind on it explicitly: `{issue:uuid}`.

## High-volume rows carry no updated_at

Spans, runs, occurrences and log entries are written once. They use `#[WithoutTimestamps]` with their own `occurred_at`, and `#[DateFormat('Y-m-d H:i:s.v')]` when the column keeps milliseconds.

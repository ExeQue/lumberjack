---
paths:
    - 'app/Domains/**'
---

# Domains

## Business code lives in a domain

`app/Domains/{Domain}/` owns its slice of the application. New domains are created freely but follow the same shape, with only the subfolders they need: `Actions/`, `Models/`, `Data/` (DTOs), `Enums/`, `Eloquent/Builders/`, `Casts/`, `Http/Requests|Controllers|Middleware/`, `Events/`, `Listeners/`, `Policies/`, `Exceptions/`, `Jobs/`, `Concerns/` (traits), `Providers/`, `Console/Commands/`. Cross-domain code goes in `app/Domains/Shared/`. Discover existing domains with `ls app/Domains`.

## Every domain ships its own ServiceProvider

A domain that needs bindings, listeners, macros or gates gets `{Domain}/Providers/{Domain}ServiceProvider.php`, registered by hand in `bootstrap/providers.php`. Do not register domain wiring from `AppServiceProvider`.

## Dependency injection everywhere

Dependencies are constructor-injected, typed and `private readonly`. Controllers, jobs, listeners and MCP tools receive Actions through the container (constructor or method injection), never with `new` and never through `app()` inside business logic.

## Domain exceptions share a marker interface

A domain with an `Exceptions/` folder has `{Domain}ExceptionInterface extends Throwable` (empty). Every concrete exception extends a real SPL exception and implements that interface, so callers can `catch ({Domain}ExceptionInterface $e)` regardless of the concrete base:

```php
interface IssueExceptionInterface extends Throwable {}

final class InvalidFingerprintException extends InvalidArgumentException implements IssueExceptionInterface {}
```

## Traits over abstract classes

Shared behavior is a trait in `Concerns/`. An abstract class only when a contract with required abstract methods is needed and a trait plus an interface cannot express it.

## JSON columns cast to DTOs through a discrete cast class

The DTO lives in `Data/`, the `CastsAttributes` implementation in `Casts/`, and the model references the cast class in `casts()`. No anonymous classes in `castUsing()`.

## Every model that needs authorization has a Policy

One Policy per model in `{Domain}/Policies/`, auto-discovered by name (`IssuePolicy` -> `Issue`). Controllers, Actions' callers and MCP tools authorize through the same Policy.

## Outbound HTTP retries transient connection failures only

Third-party calls (Discord, GitHub, mail APIs) go through one small `http()` helper on their client so every call shares a single policy:

```php
return Http::retry(
    times: 3,
    sleepMilliseconds: 200,
    when: fn (Throwable $e) => $e instanceof ConnectionException,
    throw: false,
);
```

`when` keeps HTTP error responses out of the retry loop - a 400 is an answer, not an outage. `throw: false` is load-bearing: with `tries > 1` Laravel otherwise turns every non-2xx into a `RequestException` before the client's own status handling runs. Only retry idempotent calls, or ones carrying a stable idempotency key. Queued jobs that call a provider carry `$tries` and `backoff()`.

## An exhausted retry is reported, never swallowed

When retries run out, `report()` the exception. A silent `catch` that drops an alert, a GitHub issue or an ingest batch is a bug.

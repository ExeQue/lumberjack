---
paths:
    - 'app/Domains/*/Actions/**'
---

# Actions

## One class, one job

Business logic lives in Action classes in `app/Domains/{Domain}/Actions/`, grouped in subfolders by topic once a domain has more than a handful (`Actions/Ingest/`, `Actions/Lifecycle/`). The class is named for what it does and ends in `Action`: `ResolveIssueAction`, `RecordOccurrenceAction`. The public entry point is `execute()`.

## Dependencies come through the constructor

Other Actions and services are injected as `private readonly` constructor properties, always typed. An Action is never instantiated with `new` in application code; it is resolved by the container wherever it is used.

```php
final class ResolveIssueAction
{
    public function __construct(
        private readonly RecordIssueNoteAction $recordIssueNoteAction,
    ) {}

    public function execute(Issue $issue, User $user): void
    {
        if ($issue->resolved_at !== null) {
            return;
        }

        $issue->resolved_at = now();
        $issue->save();

        event(new IssueResolved($issue, $user));
    }
}
```

## Every caller goes through the same Action

Controllers, jobs, listeners, console commands and MCP tools call the Action - none of them repeats its logic. This is what keeps the MCP server at parity with the UI: an MCP tool and the controller behind the same button call the same Action and the same Policy.

## An Action re-checks its own preconditions

Guard clauses come first and return early. An Action does not trust the caller's query to have filtered correctly - it re-verifies the state it depends on, because the next caller (a new tool, a command, tinker) will not know what the first one checked.

## Events are dispatched from one place

An Action may dispatch or broadcast domain events, and when it does, it is the only place that event is dispatched. Callers never dispatch the same event after calling the Action.

## Accumulate reasons instead of throwing for expected refusals

When an Action can legitimately refuse (a precondition a user can fix), give it a `CanHaveReasons` trait in `app/Domains/Shared/Actions/Concerns/` (the first Action that needs it adds it, copied from eazy.photo): `addReason()` records why and returns `false`, and the caller reads `$action->reasons` to explain it. Exceptions are for the unexpected, and implement the domain's exception interface.

## Shared Action helpers are traits

Behavior several Actions of one domain share goes in `{Domain}/Actions/Concerns/`; across domains in `Shared/Actions/Concerns/`.

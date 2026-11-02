---
paths:
    - 'app/Domains/*/Eloquent/**'
    - 'app/Domains/*/Models/**'
---

# Eloquent

## Custom query builders, never scopes

A query condition used more than once becomes a method on the model's custom builder - not a local scope, not a repeated inline `where()` chain. Builders live in `app/Domains/{Domain}/Eloquent/Builders/`, extend `Illuminate\Database\Eloquent\Builder`, carry a `@mixin` docblock, and are wired to the model with the attribute (not by overriding `newEloquentBuilder()`):

```php
/**
 * @mixin \Illuminate\Database\Eloquent\Builder<\App\Domains\Issues\Models\Issue>
 */
final class IssueBuilder extends Builder
{
    public function whereResolved(): self
    {
        return $this->whereNotNull('resolved_at');
    }

    public function orWhereResolved(): self
    {
        return $this->orWhereNotNull('resolved_at');
    }

    public function whereNotResolved(): self
    {
        return $this->whereNull('resolved_at');
    }

    public function orWhereNotResolved(): self
    {
        return $this->orWhereNull('resolved_at');
    }

    public function whereSeenSince(int $minutes, ?CarbonInterface $datetime = null): self
    {
        $datetime ??= now();

        return $this->where('last_seen_at', '>=', $datetime->subMinutes($minutes));
    }

    public function withOccurrences(): self
    {
        return $this->with('occurrences');
    }
}

#[UseEloquentBuilder(IssueBuilder::class)]
final class Issue extends Model {}
```

## Builder method names

- `where*` - a WHERE constraint; `whereNot*` - its negation.
- `orWhere*` / `orWhereNot*` - every `where*` and `whereNot*` has its `or` twin.
- `with*` - eager loads.

## Datetime parameters default to now

A builder method that compares against a point in time takes `?CarbonInterface $datetime = null` as its last parameter and defaults it with `$datetime ??= now();`, so tests and callers can pass a fixed moment.

## Shared builder behavior is a trait

Builder methods several models share (search, activity windows, project scoping) go in a trait under `{Domain}/Eloquent/Builders/Concerns/` or `Shared/Eloquent/Builders/Concerns/`, `use`d by each builder.

## Relations are typed, including the builder

Every relation method has a return type and a generic docblock: `@return HasMany<Occurrence, $this>`. When the related model has a custom builder, say so: `@return HasMany<Occurrence, $this>|OccurrenceBuilder<Occurrence>`.

## Conditionals stay fluent

Use `->when($condition)->whereResolved()` rather than `->when($condition, fn ($q) => $q->whereResolved())`.

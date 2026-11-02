---
paths:
    - 'database/factories/**'
    - 'app/Domains/*/Models/**'
---

# Factories

## Every model has a factory

A new model ships with its factory in the same change. Factories live flat in `database/factories/` under `Database\Factories`, named `{Model}Factory`, regardless of which domain the model belongs to.

## The model names its factory explicitly

Domain models are not in `App\Models`, so Laravel cannot guess the factory. Wire it with the attribute and type the trait:

```php
#[UseEloquentBuilder(IssueBuilder::class)]
#[UseFactory(IssueFactory::class)]
final class Issue extends Model
{
    /** @use HasFactory<IssueFactory> */
    use HasFactory;
}
```

## Factory shape

`declare(strict_types=1);`, an `@extends Factory<Model>` docblock, and a `definition()` that builds a valid, realistic row on its own: required relations through their factories (`'project_id' => Project::factory()`), enums as cases, JSON columns as their DTOs.

```php
/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Domains\Issues\Models\Issue>
 */
final class IssueFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'fingerprint' => hash('sha256', fake()->unique()->uuid()),
            'source' => IssueSource::Server,
            // ...
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn () => ['resolved_at' => now()]);
    }
}
```

## States describe situations, not columns

A state method is named for the situation a test needs - `resolved()`, `regressed()`, `fromBrowser()`, `failed()` - and returns `static`. Fields derived from other fields are fixed up in `afterMaking()` so a state cannot produce an inconsistent row. A comment explains any default that is not obvious.

## Tests build with factories and their states

Tests create every model through its factory and reach for an existing state before setting attributes by hand. A setup that spans several models goes in a `Creates…` trait under `tests/Support/{Domain}/`; a setup one model's factory can express goes in a state instead.

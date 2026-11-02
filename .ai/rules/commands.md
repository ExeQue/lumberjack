---
paths:
    - 'app/**/Console/Commands/**'
---

# Commands

## Commands declare $name plus getArguments()/getOptions()

Artisan commands set `protected $name = 'namespace:command';` and `protected $description`, then define input with `#[Override] protected function getArguments(): array` / `getOptions(): array` returning `Symfony\Component\Console\Input\InputArgument` / `InputOption` objects. Never use the `$signature` string DSL or `#[Signature]`.

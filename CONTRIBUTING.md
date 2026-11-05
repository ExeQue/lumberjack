# Contributing

Thanks for helping out. Keep a change small, focused and covered by tests.

## Before you start

- For anything bigger than a bug fix, open an issue first so we can agree on the approach.
- Security problems go through [SECURITY.md](SECURITY.md), never a public issue.

## Set up

[docs/development.md](docs/development.md) covers running Lumberjack from source, the demo data and its accounts.

## Conventions

- The code is split into domains under `app/Domains/{Domain}/`: business logic lives in Actions, reused query conditions in custom Eloquent builders, and exceptions implement their domain's `{Domain}ExceptionInterface`. Copy the shape of an existing domain.
- `.ai/rules/` holds the project's rules per area (`.ai/rules/index.md` maps paths to rule files). They apply to human contributors as much as to coding agents.
- Fixed strings go in `lang/`, not inline.
- American English in code, comments, docs and test names.
- A new or changed feature updates its page in `docs/` in the same pull request.

## Checks

Run what CI runs before you open a pull request:

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan test --parallel
npm run check
npm run types:check
```

Behavior changes come with Pest tests; pure styling and copy changes do not need them.

## Pull requests

- Use [Conventional Commits](https://www.conventionalcommits.org/) for commit messages and the pull request title, e.g. `fix(issues): keep filters when paging`.
- Describe what changed and why, and add a screenshot for UI changes.
- By contributing you agree that your work is released under the [MIT License](LICENSE).

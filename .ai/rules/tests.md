---
paths:
    - 'tests/**'
---

# Tests

## The suite mirrors the application

A test lives where the code it covers lives: `app/Domains/Issues/Actions/ResolveIssueAction.php` is covered by `tests/Feature/Domains/Issues/Actions/ResolveIssueActionTest.php`. Strip `app/`, keep the rest of the path, add the suite in front. Four folders are not mirrors of anything: `tests/Arch` for rules the codebase holds itself to, `tests/Feature/Application` for framework wiring no domain owns, `tests/Feature/Flows` for a path that crosses domains (SDK payload to issue to alert), and `tests/Feature/Views` for a rendered page. `tests/Fixtures` and `tests/Support` stay where they are.

## Unit means without the application

`tests/Unit` is for code that runs with no container, no database and no facades - a Data object, an Enum, a Support class of pure functions. Everything else is a feature test, including anything that touches a factory or a config value. A unit test that reaches for `Tests\TestCase` is a feature test in the wrong folder.

## Every test declares covers()

`covers(SomeClass::class)` at the top of the file, under the imports, using the class's short name when it is already imported. A failing run then names the class, and coverage reads per class. Only the four subjectless folders above are exempt.

## Helpers go in a trait under tests/Support

A `function` declared in a Pest file is global and the file is not autoloaded, so the next test that needs the same helper writes its own copy - and two copies under one name is a fatal error. Put it in `tests/Support/{Domain}/{Trait}.php` under the `Tests\Support\` namespace and pull it in with `uses(TraitName::class)`. The name says what a test gets out of it: `Creates…` builds models, `Fakes…` stands something in, `Reads…` pulls something out of the application, and `Builds`, `Checks`, `Writes`, `Renders`, `Configures` and `Calls` cover the rest. Prefer a factory state over a trait method when a factory can express it.

## Support classes live in tests/Fixtures, never inside a test file

A stub, a fake provider, a class built only to be tested against goes in `tests/Fixtures/{Area}/` under the `Tests\Fixtures\` namespace, one class per file.

## The same assertion with different values is a dataset

Tests that differ only in input and expected output are one test with a dataset. The row keys carry what the per-test descriptions used to say: `'a numeric id in the message' => ['User 42 not found', 'User {n} not found']`. Where the cases are the cases of an enum, add the test that fails when the enum grows a case the dataset never answers for.

## Every feature test groups its tests under describe()

Wrap tests in `describe('the topic', function () { … })` - an ability, an attack, a period, what a thing refuses. A `beforeEach` inside one belongs to that topic alone. A file with a single topic still gets its one describe. Focused unit tests under `tests/Unit` are exempt.

## it() only when the description carries on from "it"

The runner prints "it" in front of the description. A description that reads as a sentence about the thing under test - "groups occurrences by fingerprint", "rejects an unknown token" - is an `it()`. Anything else is a `test()`. Descriptions and dataset names are English and read as words with spaces.

## Pages are tested through HTTP and Inertia assertions

Exercise pages with `$this->get(...)` and assert on the Inertia component and props (`assertInertia`). Ingest and MCP endpoints are tested through their HTTP routes with the real auth. Build all fixtures with model factories.

## Nothing leaves the machine

Outbound HTTP is blocked globally with `Http::preventStrayRequests()` in `TestCase`, so every external call (Discord, GitHub, mail APIs) is faked. Double the app's own collaborators with `$this->mock(SomeClass::class)`; facade fakes cover framework services.

## A test costs time, so spend it deliberately

No `$this->seed()` in a setUp - build what the test needs with factories. Freeze the clock with `Carbon::setTestNow()` (or `$this->travelTo()`) rather than deriving dates from the real one.

## Run the suite with --parallel, one run at a time

`php artisan test --parallel` is the default. A subagent does not run the PHP suite - it writes the tests and hands back, and whoever orchestrates runs them. `--parallel` gives each worker its own database, which makes one run safe with itself and nothing else: two runs at once take the same worker databases and the second `migrate:fresh` pulls tables from under the first, showing up as `Base table or view not found` on tests that are fine. Implement as much as you can before testing, and run once.

## Assert on what the application did

Assert on responses, Inertia props, dispatched jobs and events, and what the database holds - never on whether a source file contains a string.

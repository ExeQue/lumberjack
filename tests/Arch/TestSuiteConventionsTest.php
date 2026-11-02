<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Tests\Support\Arch\ReadsTestFiles;

/**
 * The rules the suite is arranged by, checked rather than written down.
 *
 * Every one of these was a thing the suite had already drifted away from: a
 * file that never ran because of its name, a helper copied into a second file
 * because the first one hid it, a unit test that quietly booted the whole
 * application.
 */

uses(ReadsTestFiles::class);

/**
 * Folders whose tests are not about one class.
 *
 * Arch holds the codebase to its own rules, Application covers framework
 * wiring nobody owns, Flows follows a path across domains, and Views renders
 * a component rather than exercising a class.
 */
const SUBJECTLESS = [
    'tests/Arch',
    'tests/Feature/Application',
    'tests/Feature/Flows',
    'tests/Feature/Views',
];

describe('how the suite is arranged', function () {
    test('every file in the suite is a test file', function () {
        // PHPUnit collects by the Test suffix, so a file without one is a file
        // whose tests have never run and nobody has noticed.
        $strays = array_keys(array_filter(
            $this->suiteFiles(),
            fn (string $path): bool => ! str_ends_with($path, 'Test.php'),
            ARRAY_FILTER_USE_KEY,
        ));

        expect($strays)->toBe([], 'These are not collected by PHPUnit: ' . implode(', ', $strays));
    });

    test('every test says what it covers', function () {
        $missing = [];

        foreach ($this->suiteFiles() as $path => $file) {
            foreach (SUBJECTLESS as $exempt) {
                if (str_starts_with($path, $exempt)) {
                    continue 2;
                }
            }

            if (! preg_match('/^covers\(/m', $file->getContents())) {
                $missing[] = $path;
            }
        }

        expect($missing)->toBe([], 'These declare no covers(): ' . implode(', ', $missing));
    });

    test('a feature test does not repeat what Pest.php already gives it', function () {
        $offenders = [];

        foreach ($this->suiteFiles() as $path => $file) {
            if (! str_starts_with($path, 'tests/Feature')) {
                continue;
            }

            if (preg_match('/^uses\([^;]*(TestCase::class|RefreshDatabase::class)/m', $file->getContents())) {
                $offenders[] = $path;
            }
        }

        expect($offenders)->toBe([], 'Every Feature test already has both: ' . implode(', ', $offenders));
    });

    test('a unit test runs without the application', function () {
        // That is what makes it a unit test. Anything that needs a container, a
        // database or a facade is a feature test and belongs under tests/Feature.
        $offenders = [];

        foreach ($this->suiteFiles() as $path => $file) {
            if (! str_starts_with($path, 'tests/Unit')) {
                continue;
            }

            if (preg_match('/\b(TestCase::class|RefreshDatabase|extends TestCase)\b/', $file->getContents())) {
                $offenders[] = $path;
            }
        }

        expect($offenders)->toBe([], 'These boot the application: ' . implode(', ', $offenders));
    });

    test('a convention test never reaches for a database', function () {
        // These read the codebase. Anything one of them needs an object for, it
        // builds without saving it.
        $offenders = [];

        foreach ($this->suiteFiles() as $path => $file) {
            if (! str_starts_with($path, 'tests/Arch')) {
                continue;
            }

            // This file is where the patterns are written down, so it matches
            // every one of them by simply existing.
            if ($path === 'tests/Arch/TestSuiteConventionsTest.php') {
                continue;
            }

            if (preg_match('/\b(RefreshDatabase|->create\(|factory\(\)->create)\b/', $file->getContents())) {
                $offenders[] = $path;
            }
        }

        expect($offenders)->toBe([], 'These write to a database: ' . implode(', ', $offenders));
    });
});

describe('where shared code goes', function () {
    test('no test file declares a function of its own', function () {
        // A function declared in a Pest file is global, and the file is not
        // autoloaded - so the next test that needs the same helper copies it, and
        // two copies under one name is a fatal error waiting for the run that
        // loads both. Helpers belong in a trait under tests/Support.
        $offenders = [];

        foreach ($this->suiteFiles() as $path => $file) {
            if (preg_match_all('/^function (\w+)\(/m', $file->getContents(), $matches)) {
                $offenders[] = $path . ' (' . implode(', ', $matches[1]) . ')';
            }
        }

        expect($offenders)->toBe([], 'Move these into a trait under tests/Support: ' . implode(', ', $offenders));
    });

    test('every support trait is named for what it hands out', function () {
        // Creates, Fakes, Reads, Builds - the name says what a test gets by
        // pulling it in, so uses() at the top of a file reads as a list of what
        // the file was given.
        $wrong = [];

        foreach (File::allFiles(base_path('tests/Support')) as $file) {
            $name = $file->getFilenameWithoutExtension();

            if (! preg_match('/^(Creates|Fakes|Reads|Builds|Checks|Writes|Renders|Configures|Calls)[A-Z]/', $name)) {
                $wrong[] = $name;
            }
        }

        expect($wrong)->toBe([], 'Rename these: ' . implode(', ', $wrong));
    });

    test('every feature test groups its tests under describe()', function () {
        // A focused unit test is one thing asked one way, and says so by
        // living in tests/Unit. Everything else covers a class with more than
        // one job, and a failing run should name the part of it that broke.
        $missing = [];

        foreach ($this->suiteFiles() as $path => $file) {
            if (str_starts_with($path, 'tests/Unit')) {
                continue;
            }

            $contents = $file->getContents();

            // A file that only holds arch() expectations has no tests to group.
            if (! preg_match('/^\s*(it|test)\(/m', $contents)) {
                continue;
            }

            if (! str_contains($contents, 'describe(')) {
                $missing[] = $path;
            }
        }

        expect($missing)->toBe([], 'These group nothing: ' . implode(', ', $missing));
    });

    test('a test is described in English', function () {
        // The suite reads in one language, whatever language the work was
        // discussed in.
        $offenders = [];

        foreach ($this->suiteFiles() as $path => $file) {
            if ($path === 'tests/Arch/TestSuiteConventionsTest.php') {
                continue;
            }

            if (preg_match_all('/^\s*(?:it|test|describe)\([\'"]([^\'"]*[æøåÆØÅ][^\'"]*)[\'"]/m', $file->getContents(), $matches)) {
                $offenders[] = $path . ': ' . implode(', ', $matches[1]);
            }
        }

        expect($offenders)->toBe([], 'These read as Danish: ' . implode(' | ', $offenders));
    });

    test('an it() test reads as a sentence about it', function () {
        // The runner prints the group, then the word "it", then the
        // description: "every tool has to, it all tools implement
        // HasChatLabel" reads as nothing at all. A description that does not
        // carry on from "it" belongs in test().
        $offenders = [];

        foreach ($this->suiteFiles() as $path => $file) {
            if ($path === 'tests/Arch/TestSuiteConventionsTest.php') {
                continue;
            }

            preg_match_all('/^\s*it\([\'"]([^\'"]+)/m', $file->getContents(), $matches);

            foreach ($matches[1] as $description) {
                $first = strtok($description, ' ');

                // A method or a class name is the giveaway: nothing that
                // starts with code reads as a sentence about it.
                if (preg_match('/[(_]|^[A-Z]/', $first)) {
                    $offenders[] = $path . ': ' . $description;
                }
            }
        }

        expect($offenders)->toBe([], 'Use test() for these: ' . implode(' | ', $offenders));
    });
});

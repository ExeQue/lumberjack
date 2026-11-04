<?php

declare(strict_types=1);

use App\Domains\Administration\Data\NotificationPreferences;

covers(NotificationPreferences::class);

test('someone with nothing stored gets the defaults: personal kinds mailed, project kinds in app only', function () {
    expect(NotificationPreferences::fromArray(null)->toArray())->toBe([
        'watching' => ['mail' => true, 'toast' => true, 'push' => false],
        'mentions' => ['mail' => true, 'toast' => true, 'push' => true],
        'assignments' => ['mail' => true, 'toast' => true, 'push' => true],
        'new_issues' => ['mail' => false, 'toast' => true, 'push' => false],
        'health' => ['mail' => false, 'toast' => true, 'push' => false],
        'queue_backlogs' => ['mail' => false, 'toast' => true, 'push' => false],
        'scheduled_tasks' => ['mail' => false, 'toast' => true, 'push' => false],
        'agents' => ['mail' => false, 'toast' => true, 'push' => false],
        'releases' => ['mail' => false, 'toast' => true, 'push' => false],
    ])->and(NotificationPreferences::defaults()->toArray())->toBe(NotificationPreferences::DEFAULTS)
        ->and(array_keys(NotificationPreferences::DEFAULTS))->toBe(NotificationPreferences::KINDS);
});

test('stored choices win and whatever was never stored takes its default', function () {
    $preferences = NotificationPreferences::fromArray([
        'watching' => ['mail' => false, 'toast' => true],
        'mentions' => ['mail' => true, 'toast' => false],
        'health' => ['push' => true],
        'gone_kind' => ['mail' => true],
    ]);

    expect($preferences->mail('watching'))->toBeFalse()
        ->and($preferences->push('watching'))->toBeFalse()
        ->and($preferences->toast('mentions'))->toBeFalse()
        ->and($preferences->push('mentions'))->toBeTrue()
        ->and($preferences->push('health'))->toBeTrue()
        ->and($preferences->toast('health'))->toBeTrue()
        ->and($preferences->mail('health'))->toBeFalse()
        ->and($preferences->toArray())->not->toHaveKey('gone_kind');
});

test('an unknown kind is off everywhere', function () {
    $preferences = NotificationPreferences::defaults();

    expect([$preferences->mail('nope'), $preferences->toast('nope'), $preferences->push('nope')])->toBe([false, false, false]);
});

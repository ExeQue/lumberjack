<?php

declare(strict_types=1);

use App\Domains\Administration\Http\Controllers\AdminSystemController;
use App\Domains\Administration\Models\SystemSample;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Administration\FakesSystemProbe;

covers(AdminSystemController::class);

uses(FakesSystemProbe::class);

beforeEach(function () {
    $this->admin = User::factory()->systemAdmin()->create();
});

describe('the system page', function () {
    it('shows an administrator how the server is doing', function () {
        $this->fakeSystem();
        SystemSample::factory()->load(0.5, 0.25, 0.4)->create();

        $this->actingAs($this->admin)->get(route('admin.system'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/system')
                ->where('period', '24h')
                ->where('latest.cpu_load_1m', 2)
                ->where('queues.0.name', 'ingest')
                ->where('redis.used_bytes', 12 * 1024 ** 2)
                ->has('charts.memory')
                ->has('database.tables'));
    });

    it('reloads the queues without measuring the database again', function () {
        $this->fakeSystem();

        $this->actingAs($this->admin)->get(route('admin.system'))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly(['queues', 'redis'], fn (Assert $reload) => $reload
                ->where('queues.0.name', 'ingest')
                ->has('redis')
                ->missing('database')
                ->missing('charts')));
    });

    it('still opens when Redis is down and nothing was sampled', function () {
        $this->fakeSystem(redisDown: true);

        $this->actingAs($this->admin)->get(route('admin.system'))
            ->assertInertia(fn (Assert $page) => $page->where('latest', null)->where('queues', null)->where('redis', null));
    });

    it('offers the periods the samples are kept for', function (string $asked, string $shown) {
        $this->fakeSystem();

        $this->actingAs($this->admin)->get(route('admin.system', ['period' => $asked]))
            ->assertInertia(fn (Assert $page) => $page->where('period', $shown));
    })->with([
        'an hour' => ['1h', '1h'],
        'a week' => ['7d', '7d'],
        'a month, which is longer than they are kept' => ['30d', '24h'],
        'nonsense' => ['soon', '24h'],
    ]);

    it('is closed to someone who is not an administrator', function () {
        $this->actingAs(User::factory()->inTeam()->create())->get(route('admin.system'))->assertForbidden();
    });
});

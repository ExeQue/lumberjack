<?php

declare(strict_types=1);

use App\Domains\Connectors\MobilePay\Actions\ConnectMobilePayAction;
use App\Domains\Connectors\MobilePay\Http\Controllers\MobilePayConnectionController;
use App\Domains\Connectors\MobilePay\Http\Controllers\MobilePayController;
use App\Domains\Connectors\MobilePay\Http\Requests\ConnectMobilePayRequest;
use App\Domains\Connectors\MobilePay\Queries\MobilePayDashboardQuery;
use App\Domains\DataSources\Enums\ConnectionKind;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Connectors\FakesMobilePay;

covers(MobilePayController::class, MobilePayConnectionController::class, ConnectMobilePayRequest::class, ConnectMobilePayAction::class, MobilePayDashboardQuery::class);

uses(FakesMobilePay::class);

beforeEach(function () {
    $this->travelTo('2026-11-10 12:00:00');
    $this->fakeMobilePay();
    $this->project = Project::factory()->create();
    $this->admin = User::factory()->inTeam($this->project->team)->create();
    $this->member = User::factory()->inTeam($this->project->team, TeamRole::Member)->create();
    $this->keys = ['client_id' => 'client-id', 'client_secret' => 'client-secret', 'subscription_key' => 'subscription-key', 'msn' => '123455', 'timezone' => 'Europe/Oslo'];
});

describe('connecting a ledger', function () {
    it('looks up the ledger, keeps the keys encrypted and opens its page', function () {
        $response = $this->actingAs($this->admin)->post(route('connectors.mobilepay.connection.store', $this->project), $this->keys)->assertSessionHasNoErrors();

        $connection = DataSourceConnection::query()->sole();
        $config = $connection->mobilePayConfig();

        $response->assertRedirect(route('connectors.mobilepay.index', [$this->project, $connection]));
        expect($connection->kind)->toBe(ConnectionKind::MobilePay)
            ->and($connection->name)->toBe('ACME Fitness Oslo')
            ->and($connection->timezone)->toBe('Europe/Oslo')
            ->and($config->ledgerId)->toBe('302321')
            ->and($config->currency)->toBe('NOK')
            ->and($config->credentials->clientSecret)->toBe('client-secret')
            ->and(DB::table('data_source_connections')->value('config'))->not->toContain('client-secret');
    });

    it('renews the connection of a ledger it already reads', function () {
        $this->actingAs($this->admin);
        $this->post(route('connectors.mobilepay.connection.store', $this->project), $this->keys);
        $this->post(route('connectors.mobilepay.connection.store', $this->project), [...$this->keys, 'client_secret' => 'renewed']);

        expect($this->project->mobilePayConnections()->sole()->mobilePayConfig()->credentials->clientSecret)->toBe('renewed');
    });

    it('says on the secret why it cannot connect, saving nothing', function (string $path, array $answer, string $error) {
        $this->mobilePayAnswers[$path] = $answer;

        $this->actingAs($this->admin)->post(route('connectors.mobilepay.connection.store', $this->project), $this->keys)
            ->assertSessionHasErrors(['client_secret' => $error]);

        expect(DataSourceConnection::query()->count())->toBe(0);
    })->with([
        'refused keys' => ['/accesstoken/get', [['error' => 'unauthorized_client'], 401], 'Vipps MobilePay refused the API keys. Check the client ID, client secret, subscription key and merchant serial number in the business portal.'],
        'no ledger for the number' => ['/settlement/v1/ledgers', [['items' => [], 'cursor' => '']], 'The API keys see no ledger for merchant serial number 123455. Check the number in the business portal.'],
    ]);

    it('is for those who administer the project', function () {
        $this->actingAs($this->member)->post(route('connectors.mobilepay.connection.store', $this->project), $this->keys)->assertForbidden();
    });
});

describe('the Vipps MobilePay page', function () {
    it('offers to connect one, and keeps it out of the sidebar', function () {
        $this->actingAs($this->member)->get(route('connectors.mobilepay.index', $this->project))
            ->assertInertia(fn (Assert $page) => $page->component('connectors/mobilepay/index')->where('connection', null)->where('sidebar_project.connectors', []));
    });

    it('shows a week\'s figures against the week before, and the revenue of each day', function () {
        $connection = DataSourceConnection::factory()->mobilePay()->for($this->project)->create(['timezone' => 'UTC']);

        $this->actingAs($this->member)->get(route('connectors.mobilepay.index', [$this->project, $connection]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('error', null)
                ->where('filters.period', '7d')
                ->where('summary.currency', 'NOK')
                ->where('summary.current', ['revenue' => 849, 'transactions' => 4, 'refunds' => 100, 'fees' => 15, 'payouts' => 484, 'average_order' => 212.25, 'refund_rate' => 11.8, 'fee_rate' => 1.8])
                ->where('summary.previous', ['revenue' => 300, 'transactions' => 1, 'refunds' => 0, 'fees' => 0, 'payouts' => 0, 'average_order' => 300, 'refund_rate' => 0, 'fee_rate' => 0])
                ->where('summary.balance', 250)
                ->has('series', 7)
                ->where('series.0.t', '2026-11-04T00:00:00+00:00')
                ->where('series.6', ['t' => '2026-11-10T00:00:00+00:00', 'revenue' => 250, 'transactions' => 2])
                ->has('sidebar_project.connectors.mobilepay', 1));
    });

    it('shows the period\'s transactions, largest sales, payouts and breakdowns, times in the connection\'s zone', function () {
        $connection = DataSourceConnection::factory()->mobilePay()->for($this->project)->create(['timezone' => 'Europe/Oslo']);

        $this->actingAs($this->member)->get(route('connectors.mobilepay.index', [$this->project, $connection]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows', [])
                ->has('details.latest', 5)
                ->where('details.latest.0', ['at' => '2026-11-10T12:00:00+01:00', 'type' => 'capture', 'amount' => 50, 'reference' => 'order-d'])
                ->where('details.latest.2', ['at' => '2026-11-09T20:00:00+01:00', 'type' => 'refund', 'amount' => -100, 'reference' => 'order-b'])
                ->where('details.largest.0', ['at' => '2026-11-09T10:15:00+01:00', 'amount' => 499, 'reference' => 'acme-shop-123-order123abc'])
                ->where('details.payouts', [['date' => '2026-11-09', 'amount' => 484]])
                ->has('details.hours', 24)
                ->where('details.hours.10', ['hour' => 10, 'sales' => 1, 'revenue' => 499])
                ->where('details.weekdays.0', ['label' => 'Monday', 'sales' => 2, 'revenue' => 599])
                ->where('details.units', [['unit' => 'NO:123455', 'revenue' => 749], ['unit' => 'NO:123456', 'revenue' => 100]]));
    });

    it('shows today, each day of a month, and nothing finer than a day', function (string $period, string $shown, int $days) {
        $connection = DataSourceConnection::factory()->mobilePay()->for($this->project)->create(['timezone' => 'UTC']);

        $this->actingAs($this->member)->get(route('connectors.mobilepay.index', [$this->project, $connection, 'period' => $period]))
            ->assertInertia(fn (Assert $page) => $page->where('filters.period', $shown)->has('series', $days));
    })->with([['24h', '24h', 1], ['30d', '30d', 30], ['1h', '7d', 7]]);

    it('says why when Vipps MobilePay cannot be read', function () {
        DataSourceConnection::factory()->mobilePay()->for($this->project)->create();
        $this->mobilePayAnswers['/accesstoken/get'] = [['error' => 'unauthorized_client'], 401];

        $this->actingAs($this->member)->get(route('connectors.mobilepay.index', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('error', __('connectors.mobilepay.errors.unauthorized'))->where('summary', null));
    });

    it('knows no ledger on another kind of connection', function () {
        $this->actingAs($this->member)->get(route('connectors.mobilepay.index', [$this->project, DataSourceConnection::factory()->mosparo()->for($this->project)->create()]))->assertNotFound();
    });
});

describe('loading more of a list', function () {
    beforeEach(function () {
        $this->connection = DataSourceConnection::factory()->mobilePay()->for($this->project)->create(['timezone' => 'UTC']);
    });

    it('shows more of the kept entries without asking Vipps MobilePay again', function () {
        $this->actingAs($this->member)->get(route('connectors.mobilepay.index', [$this->project, $this->connection]));
        $asked = Http::recorded()->count();

        $this->get(route('connectors.mobilepay.index', [$this->project, $this->connection, 'rows' => ['transactions' => 40, 'largest' => 20]]))
            ->assertInertia(fn (Assert $page) => $page->where('rows', ['transactions' => 40, 'largest' => 20])->has('details.latest', 5)->has('details.largest', 4));

        expect(Http::recorded()->count())->toBe($asked);
    });

    it('holds each list to its most, and to its first rows for a count it cannot read', function () {
        $this->actingAs($this->member)->get(route('connectors.mobilepay.index', [$this->project, $this->connection, 'rows' => ['transactions' => 9999, 'largest' => 500, 'payouts' => 'many', 'unknown' => 50]]))
            ->assertInertia(fn (Assert $page) => $page->where('rows', ['transactions' => 200, 'largest' => 100]));
    });
});

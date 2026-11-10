<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Umami\Http\Controllers;

use App\Domains\Connectors\Exceptions\ConnectorsExceptionInterface;
use App\Domains\Connectors\Umami\Actions\ConnectUmamiAction;
use App\Domains\Connectors\Umami\Actions\CreateUmamiAnnotationAction;
use App\Domains\Connectors\Umami\Actions\DeleteUmamiAnnotationAction;
use App\Domains\Connectors\Umami\Actions\ListUmamiWebsitesAction;
use App\Domains\Connectors\Umami\Actions\SaveUtmLabelAction;
use App\Domains\Connectors\Umami\Enums\UtmDimension;
use App\Domains\Connectors\Umami\Http\Requests\ChangeUmamiWebsiteRequest;
use App\Domains\Connectors\Umami\Http\Requests\ConnectUmamiRequest;
use App\Domains\Connectors\Umami\Http\Requests\CreateUmamiAnnotationRequest;
use App\Domains\Connectors\Umami\Http\Requests\SaveUtmLabelRequest;
use App\Domains\Connectors\Umami\Http\Requests\UmamiCredentialsRequest;
use App\Domains\DataSources\Enums\ConnectionKind;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Connecting a project to an Umami website: the websites credentials can read are listed
 * to pick from, and the saved credentials never leave the server.
 */
class UmamiConnectionController
{
    public function websites(UmamiCredentialsRequest $request, Project $project, ListUmamiWebsitesAction $action): JsonResponse
    {
        try {
            return response()->json(['websites' => $action->execute($request->credentials())]);
        } catch (ConnectorsExceptionInterface $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['secret' => [$e->getMessage()]]], 422);
        }
    }

    public function savedWebsites(Project $project, DataSourceConnection $dataSourceConnection, ListUmamiWebsitesAction $action): JsonResponse
    {
        self::ensureUmami($dataSourceConnection);

        try {
            return response()->json(['websites' => $action->execute($dataSourceConnection->umamiCredentials())]);
        } catch (ConnectorsExceptionInterface $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (DecryptException) {
            return response()->json(['message' => __('connectors.umami.errors.secret_unreadable')], 422);
        }
    }

    public function store(ConnectUmamiRequest $request, Project $project, ConnectUmamiAction $action): RedirectResponse
    {
        try {
            $connection = $action->execute($project, $request->credentials(), $request->string('website_id')->toString(), $request->string('timezone', 'UTC')->toString());
        } catch (ConnectorsExceptionInterface $e) {
            throw ValidationException::withMessages(['website_id' => $e->getMessage()]);
        }

        return self::toWebsite($project, $connection);
    }

    /** Connects another website with the credentials a connection already has. */
    public function add(ChangeUmamiWebsiteRequest $request, Project $project, DataSourceConnection $dataSourceConnection, ConnectUmamiAction $action): RedirectResponse
    {
        self::ensureUmami($dataSourceConnection);

        try {
            $connection = $action->execute($project, $dataSourceConnection->umamiCredentials(), $request->string('website_id')->toString(), $dataSourceConnection->timezone);
        } catch (ConnectorsExceptionInterface $e) {
            throw ValidationException::withMessages(['website_id' => $e->getMessage()]);
        } catch (DecryptException) {
            throw ValidationException::withMessages(['website_id' => __('connectors.umami.errors.secret_unreadable')]);
        }

        return self::toWebsite($project, $connection);
    }

    public function update(ChangeUmamiWebsiteRequest $request, Project $project, DataSourceConnection $dataSourceConnection, ConnectUmamiAction $action): RedirectResponse
    {
        self::ensureUmami($dataSourceConnection);

        try {
            $connection = $action->changeWebsite($dataSourceConnection, $request->string('website_id')->toString());
        } catch (ConnectorsExceptionInterface $e) {
            throw ValidationException::withMessages(['website_id' => $e->getMessage()]);
        } catch (DecryptException) {
            throw ValidationException::withMessages(['website_id' => __('connectors.umami.errors.secret_unreadable')]);
        }

        return back()->with('status', __('connectors.umami.connected', ['website' => $connection->name]));
    }

    public function label(SaveUtmLabelRequest $request, Project $project, DataSourceConnection $dataSourceConnection, SaveUtmLabelAction $action): RedirectResponse
    {
        self::ensureUmami($dataSourceConnection);
        $action->execute($dataSourceConnection, UtmDimension::from($request->string('dimension')->toString()), $request->string('value')->toString(), $request->input('label'));

        return back();
    }

    public function annotate(CreateUmamiAnnotationRequest $request, Project $project, DataSourceConnection $dataSourceConnection, CreateUmamiAnnotationAction $action): RedirectResponse
    {
        self::ensureUmami($dataSourceConnection);
        $time = $request->string('time')->toString();
        $at = CarbonImmutable::parse($request->string('date')->toString().' '.($time === '' ? '00:00' : $time), $dataSourceConnection->timezone);

        try {
            $action->execute($dataSourceConnection, $at, $time === '', $request->string('note')->toString());
        } catch (ConnectorsExceptionInterface $e) {
            throw ValidationException::withMessages(['note' => $e->getMessage()]);
        }

        return back();
    }

    public function unannotate(Project $project, DataSourceConnection $dataSourceConnection, string $annotation, DeleteUmamiAnnotationAction $action): RedirectResponse
    {
        self::ensureUmami($dataSourceConnection);

        try {
            $action->execute($dataSourceConnection, $annotation);
        } catch (ConnectorsExceptionInterface $e) {
            return back()->with('status', $e->getMessage());
        }

        return back();
    }

    /** Only an Umami connection has a website, and only those administering it change it. */
    private static function ensureUmami(DataSourceConnection $connection): void
    {
        abort_unless($connection->isFor(ConnectionKind::Umami), 404);
        Gate::authorize('update', $connection);
    }

    private static function toWebsite(Project $project, DataSourceConnection $connection): RedirectResponse
    {
        return to_route('connectors.umami.index', [$project, $connection])
            ->with('status', __('connectors.umami.connected', ['website' => $connection->name]));
    }
}

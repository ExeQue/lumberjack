<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Umami\Actions;

use App\Domains\Connectors\Umami\Exceptions\UmamiRequestFailedException;
use App\Domains\Connectors\Umami\Queries\UmamiDashboardQuery;
use App\Domains\Connectors\Umami\Support\UmamiClient;
use App\Domains\DataSources\Enums\ConnectionKind;
use App\Domains\DataSources\Models\DataSourceConnection;
use Carbon\CarbonImmutable;

/**
 * Marks a moment on an Umami website, such as a campaign's launch, as an annotation in
 * Umami itself, so it shows on Umami's charts as well as on the Umami page.
 */
class CreateUmamiAnnotationAction
{
    public function __construct(
        private readonly UmamiClient $client,
    ) {
    }

    /**
     * @param  CarbonImmutable  $at  the day, or the moment, in the connection's time zone
     *
     * @throws UmamiRequestFailedException
     */
    public function execute(DataSourceConnection $connection, CarbonImmutable $at, bool $allDay, string $note): void
    {
        $note = trim($note);

        if (! $connection->isFor(ConnectionKind::Umami) || $note === '') {
            return;
        }

        $this->client->createAnnotation($connection->umamiCredentials(), $connection->umamiWebsiteId(), $at, $allDay, $note);
        UmamiDashboardQuery::forgetAnnotations($connection);
    }
}

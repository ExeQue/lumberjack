<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Umami\Actions;

use App\Domains\Connectors\Umami\Exceptions\UmamiRequestFailedException;
use App\Domains\Connectors\Umami\Queries\UmamiDashboardQuery;
use App\Domains\Connectors\Umami\Support\UmamiClient;
use App\Domains\DataSources\Enums\ConnectionKind;
use App\Domains\DataSources\Models\DataSourceConnection;

/**
 * Removes an annotation from the Umami website, in Umami too.
 */
class DeleteUmamiAnnotationAction
{
    public function __construct(
        private readonly UmamiClient $client,
    ) {
    }

    /**
     * @throws UmamiRequestFailedException
     */
    public function execute(DataSourceConnection $connection, string $annotationId): void
    {
        if (! $connection->isFor(ConnectionKind::Umami)) {
            return;
        }

        $this->client->deleteAnnotation($connection->umamiCredentials(), $connection->umamiWebsiteId(), $annotationId);
        UmamiDashboardQuery::forgetAnnotations($connection);
    }
}

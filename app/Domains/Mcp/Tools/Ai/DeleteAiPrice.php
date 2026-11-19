<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Ai;

use App\Domains\Ai\Actions\DeleteAiPriceAction;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesAiUsage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[RequiresPermission(McpArea::Ai, McpLevel::Manage)]
class DeleteAiPrice extends Tool
{
    use UsesAiUsage;

    protected string $name = 'delete_ai_price';

    protected string $description = 'Deletes an AI price, of the project or its team. Cost is never stored, so the usage it priced is priced by the next price that applies, or not at all, also in the past.';

    public function __construct(private readonly DeleteAiPriceAction $delete)
    {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'price' => $schema->string()->description('The price\'s uuid; see get_ai_key.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $price = $this->aiPrice($request, $project);

        if ($price instanceof Response) {
            return $price;
        }

        $denied = $this->authorizePrice('delete', $price, $project);

        if ($denied !== null) {
            return $denied;
        }

        $this->delete->execute($price);

        return Response::json(['deleted' => $price->uuid]);
    }
}

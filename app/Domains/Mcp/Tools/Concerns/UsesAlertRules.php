<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Concerns;

use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Presenters\AlertRulePresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Alert rules as the alert tools name them: a type, and for custom alerts a topic, always
 * within the token's team.
 */
trait UsesAlertRules
{
    use UsesMcpContext;

    /**
     * @return array<string, mixed>
     */
    protected function ruleArguments(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(array_map(fn (AlertRuleType $type): string => $type->value, AlertRuleType::cases()))->description('The rule type; see list_alert_rules.')->required(),
            'topic' => $schema->string()->description('Only for custom_alert: the topic, see list_alert_topics. Defaults to "default".'),
        ];
    }

    /**
     * The team's rule the request names, the one its projects use unless they customize it.
     */
    protected function teamRule(Request $request, ?AlertRuleType $type = null): AlertRule|Response
    {
        $type ??= AlertRuleType::tryFrom((string) $request->get('type'));

        if ($type === null) {
            return Response::error('Unknown rule type; see list_alert_rules.');
        }

        $rule = AlertRule::query()->whereForTeam($this->context()->token()->team)->whereType($type)->where('topic', $this->topic($request, $type))->with('channels:id')->first();

        return $rule ?? Response::error($type === AlertRuleType::CustomAlert ? 'Unknown topic; see list_alert_topics.' : 'The team has no rule of this type.');
    }

    protected function topic(Request $request, AlertRuleType $type): string
    {
        return $type === AlertRuleType::CustomAlert ? ((string) $request->get('topic', '') ?: AlertRule::DEFAULT_TOPIC) : '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentRule(AlertRule $rule): array
    {
        return [...app(AlertRulePresenter::class)->present($rule->load('channels:id')), 'topic' => $rule->topic !== '' ? $rule->topic : null];
    }
}

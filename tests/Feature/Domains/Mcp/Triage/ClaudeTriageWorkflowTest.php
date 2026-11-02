<?php

declare(strict_types=1);

use App\Domains\Mcp\Triage\ClaudeTriageWorkflow;
use App\Domains\Mcp\Triage\TriagePrompt;
use App\Domains\Projects\Models\Project;
use Symfony\Component\Yaml\Yaml;

covers(ClaudeTriageWorkflow::class, TriagePrompt::class);

describe('the Claude triage workflow', function () {
    it('is a valid workflow that points Claude at this Lumberjack with the shared prompt and the allowed tools', function () {
        $project = Project::factory()->create(['slug' => 'shop']);

        $workflow = Yaml::parse(app(ClaudeTriageWorkflow::class)->render($project, 'https://lumberjack.example.com/'));
        $step = $workflow['jobs']['triage']['steps'][2];
        $issueStep = $workflow['jobs']['triage']['steps'][3];

        expect($workflow['on']['schedule'][0]['cron'])->toBe('*/15 * * * *')
            ->and($step['uses'])->toBe('anthropics/claude-code-action@v1')
            ->and($step['with'])->toHaveKey('claude_code_oauth_token')->not->toHaveKey('anthropic_api_key')
            ->and($step['with']['claude_args'])->toContain('"url":"https://lumberjack.example.com/mcp"')
            ->toContain('${{ secrets.LUMBERJACK_MCP_TOKEN }}')
            ->toContain('mcp__lumberjack__triage_inbox')
            ->toContain('mcp__lumberjack__set_issue_labels')
            ->toContain('Bash(git push -u origin lumberjack/:*)')
            ->not->toContain('Bash(git push:*)')
            ->and($workflow['permissions'])->toMatchArray(['contents' => 'write', 'pull-requests' => 'write'])
            ->and($workflow['jobs']['triage']['if'])->toContain("contains(github.event.issue.labels.*.name, 'lumberjack')")
            ->and($issueStep['with']['prompt'])->toContain('gh issue view ${{ github.event.issue.number }}')
            ->and(trim($step['with']['prompt']))->toBe(trim(app(TriagePrompt::class)->render($project)))
            ->and($step['with']['prompt'])->toContain('"shop"');
    });

    it('gives a local command whose config takes the token from the environment', function () {
        $command = app(ClaudeTriageWorkflow::class)->localCommand(Project::factory()->create(['slug' => 'shop']), 'https://lumberjack.example.com');

        expect($command)->toStartWith('claude "Triage the new issues')
            ->toEndWith('}}}}"')
            ->toContain('https://lumberjack.example.com/mcp')
            ->toContain('Bearer $LUMBERJACK_MCP_TOKEN')
            ->toContain('project shop');
    });
});

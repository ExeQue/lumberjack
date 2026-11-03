<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Triage;

use App\Domains\Projects\Models\Project;

/**
 * A GitHub Actions workflow that runs Claude every 15 minutes to triage the
 * project's new issues and answer notes that tag it, through Lumberjack's MCP
 * server, with the repository checked out so it can read the code.
 */
final class ClaudeTriageWorkflow
{
    public const string KEY = 'claude';

    public const string PATH = '.github/workflows/lumberjack-triage.yml';

    public const string SECRET_TOKEN = 'LUMBERJACK_MCP_TOKEN';

    /** The label on the GitHub issues Lumberjack opens; the workflow answers on those. */
    public const string LABEL = 'lumberjack';

    public function __construct(
        private readonly TriagePrompt $prompt,
    ) {
    }

    /**
     * @return array{key: string, name: string, path: string, secrets: list<string>, workflow: string, local: string}
     */
    public function describe(Project $project, string $baseUrl): array
    {
        return [
            'key' => self::KEY,
            'name' => 'Claude',
            'path' => self::PATH,
            // A Claude subscription by default; ANTHROPIC_API_KEY works too, see the workflow.
            'secrets' => [self::SECRET_TOKEN, 'CLAUDE_CODE_OAUTH_TOKEN'],
            'workflow' => $this->render($project, $baseUrl),
            'local' => $this->localCommand($project, $baseUrl),
        ];
    }

    /**
     * A local Claude Code session with Lumberjack's MCP server set up for this run
     * only. The token comes from the environment, so it stays out of shell history.
     */
    public function localCommand(Project $project, string $baseUrl): string
    {
        $config = json_encode(['mcpServers' => ['lumberjack' => [
            'type' => 'http',
            'url' => rtrim($baseUrl, '/').'/mcp',
            'headers' => ['Authorization' => 'Bearer ${'.self::SECRET_TOKEN.'}'],
        ]]], JSON_UNESCAPED_SLASHES);

        // Double quotes, so the shell fills in the token; the JSON's own quotes escaped.
        $config = str_replace(['"', '${'], ['\\"', '$'], (string) $config);
        $config = str_replace('$'.self::SECRET_TOKEN.'}', '$'.self::SECRET_TOKEN, $config);

        // The prompt goes first: --mcp-config takes every argument after it as another config.
        return 'claude "Triage the new issues of the Lumberjack project '.$project->slug.': call triage_inbox and work through what it returns." --mcp-config "'.$config.'"';
    }

    public function render(Project $project, string $baseUrl): string
    {
        $mcp = '--mcp-config \'{"mcpServers":{"lumberjack":{"type":"http","url":"'.rtrim($baseUrl, '/').'/mcp","headers":{"Authorization":"Bearer ${{ secrets.'.self::SECRET_TOKEN.' }}"}}}}\'';
        $read = ['Read', 'Grep', 'Glob', 'Bash(git log:*)', 'Bash(git show:*)', 'Bash(git blame:*)'];
        // For a draft pull request with a fix; the prompt keeps it to a branch of its own.
        $change = ['Edit', 'Write', 'Bash(git checkout -b lumberjack/:*)', 'Bash(git add:*)', 'Bash(git commit:*)', 'Bash(git push -u origin lumberjack/:*)', 'Bash(gh pr create:*)'];
        $lumberjack = array_map(fn (string $tool): string => 'mcp__lumberjack__'.$tool, TriagePrompt::TOOLS);

        return strtr(self::TEMPLATE, [
            '{project}' => $project->name,
            '{slug}' => $project->slug,
            '{label}' => self::LABEL,
            '{secret}' => self::SECRET_TOKEN,
            '{mcp}' => $mcp,
            '{inbox_tools}' => implode(',', [...$read, ...$change, ...$lumberjack]),
            '{issue_tools}' => implode(',', [...$read, ...$change, 'Bash(gh issue view:*)', 'Bash(gh issue comment:*)', ...$lumberjack]),
            '{inbox_prompt}' => self::indent($this->prompt->render($project)),
            '{issue_prompt}' => self::indent($this->prompt->forRepoIssue($project, '${{ github.event.issue.number }}')),
        ]);
    }

    private static function indent(string $text): string
    {
        return implode("\n", array_map(fn (string $line): string => $line === '' ? '' : '            '.$line, explode("\n", $text)));
    }

    private const string TEMPLATE = <<<'YAML'
# Lumberjack Triage for {project}. Claude works through Lumberjack's MCP server,
# with this repository checked out so it can read the code:
#   - every 15 minutes it triages the new and regressed issues, and answers the
#     notes in Lumberjack that tag it;
#   - when a GitHub issue with the "{label}" label (the ones Lumberjack opens) is
#     opened or commented on, it answers there;
#   - when it is sure of a cause and the fix is small, it opens a draft pull request
#     on a lumberjack/<issue> branch, never on the default branch, and never merges.
#
# Pull requests opened with the workflow's own token do not start other workflows,
# so CI will not run on them until someone pushes to the branch or re-runs it.
#
# Secrets: {secret} (an MCP token with the write scope covering {slug})
# and CLAUDE_CODE_OAUTH_TOKEN, which runs on your Claude subscription: create it
# with `claude setup-token`. To pay per use instead, set ANTHROPIC_API_KEY and
# swap the two lines in each step below.
#
# Commits are by "Lumberjack". To show a logo on them, set the repository variable
# LUMBERJACK_GIT_EMAIL to the email of a GitHub account or app with that avatar, such
# as a GitHub App's bot: <bot user id>+<app slug>[bot]@users.noreply.github.com.
name: Lumberjack Triage
run-name: "Lumberjack Triage${{ github.event.issue && format(' · #{0}', github.event.issue.number) || '' }}"

on:
  schedule:
    - cron: "*/15 * * * *"
  workflow_dispatch:
  issues:
    types: [opened, labeled]
  issue_comment:
    types: [created]

concurrency:
  group: lumberjack-triage-${{ github.event.issue.number || 'inbox' }}
  cancel-in-progress: false

permissions:
  contents: write
  issues: write
  pull-requests: write
  id-token: write

jobs:
  triage:
    # Scheduled and manual runs, and GitHub issues labelled "{label}"; never Claude's own comments.
    if: >-
      github.event_name == 'schedule' || github.event_name == 'workflow_dispatch' ||
      (github.event_name == 'issues' && contains(github.event.issue.labels.*.name, '{label}')) ||
      (github.event_name == 'issue_comment' && !github.event.issue.pull_request &&
        contains(github.event.issue.labels.*.name, '{label}') && github.event.comment.user.type != 'Bot')
    runs-on: ubuntu-latest
    timeout-minutes: 20
    env:
      GH_TOKEN: ${{ github.token }}
    steps:
      - uses: actions/checkout@v5
        with:
          fetch-depth: 200

      - name: Commit as the triage agent
        run: |
          git config user.name "Lumberjack"
          git config user.email "${{ vars.LUMBERJACK_GIT_EMAIL || 'lumberjack@noreply.invalid' }}"

      - name: Triage the Lumberjack inbox
        if: github.event_name == 'schedule' || github.event_name == 'workflow_dispatch'
        uses: anthropics/claude-code-action@v1
        with:
          claude_code_oauth_token: ${{ secrets.CLAUDE_CODE_OAUTH_TOKEN }}
          # anthropic_api_key: ${{ secrets.ANTHROPIC_API_KEY }}
          claude_args: >-
            {mcp}
            --allowedTools "{inbox_tools}"
            --max-turns 60
          prompt: |
{inbox_prompt}

      - name: Answer on the GitHub issue
        if: github.event_name == 'issues' || github.event_name == 'issue_comment'
        uses: anthropics/claude-code-action@v1
        with:
          claude_code_oauth_token: ${{ secrets.CLAUDE_CODE_OAUTH_TOKEN }}
          # anthropic_api_key: ${{ secrets.ANTHROPIC_API_KEY }}
          claude_args: >-
            {mcp}
            --allowedTools "{issue_tools}"
            --max-turns 40
          prompt: |
{issue_prompt}

YAML;
}

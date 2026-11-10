<?php

declare(strict_types=1);

return [
    'title' => 'Members and tokens',
    'none' => [
        'title' => 'Not in a team yet',
        'body' => 'You join a team when someone invites you to it. Ask a team owner or an administrator of this Lumberjack for an invitation; it arrives by email.',
    ],
    'actions' => 'Actions',
    'members' => [
        'title' => 'Members',
        'description' => 'Only team members have access to the team\'s projects.',
        'name' => 'Name',
        'email' => 'Email',
        'role' => 'Role',
        'role_for' => 'Role for :name',
        'you' => '(you)',
        // Button
        'transfer' => 'Transfer ownership',
        'transfer_question' => 'Make :name the owner? You become an admin.',
        // Button
        'remove' => 'Remove',
        'remove_question' => 'Remove :name from the team?',
    ],
    'invitations' => [
        'resend' => 'Send again',
        'title' => 'Open invitations',
        'expires' => 'expires :time',
        'expired' => 'Expired',
        // Button
        'revoke' => 'Revoke',
        'revoke_question' => 'Revoke the invitation for :email? The link stops working.',
        'email' => 'Invite by email',
        'email_placeholder' => 'name@company.com',
        // Button
        'send' => 'Send invitation',
    ],
    'tokens' => [
        'title' => 'API and MCP tokens',
        'description' => 'Gives an agent access to the selected projects through MCP, and Grafana or a script read access through the metrics API.',
        'endpoint' => 'Endpoint:',
        'api_endpoint' => 'Metrics API:',
        'connect' => [
            // Button and dialog title
            'title' => 'Connect a client',
            'description' => 'Set up an MCP client for :url with one of the team\'s tokens.',
            'several' => 'Tip: using more than one token, for other teams, servers or permissions? Give each its own server name and variable, such as lumberjack-work with LUMBERJACK_WORK_MCP_TOKEN, or set LUMBERJACK_MCP_TOKEN per repository with a tool like direnv in a git-ignored .envrc.',
            'warning' => 'A token opens your team\'s data like a password. Never put the token itself in a file you commit, such as .mcp.json or .cursor/mcp.json: keep it in an environment variable or your client\'s secret store, as below.',
            'client' => 'Client',
            'env' => 'Then set :env where the client runs, with your token in place of the placeholder. Use a shell profile that is not in a dotfiles repository, or a secret manager. On Windows, run setx :env with the token instead.',
            // Accessible names of the Copy buttons
            'copy_token' => 'Copy token',
            'copy_setup' => 'Copy setup',
            'copy_env' => 'Copy variable',
            'clients' => [
                'claude_code' => [
                    'name' => 'Claude Code',
                    'where' => 'Run in your repository. It writes .mcp.json with the variable, not the token, so the file can be committed.',
                ],
                'codex' => [
                    'name' => 'Codex',
                    'where' => 'Run once. It adds the server to ~/.codex/config.toml, reading the token from the variable.',
                ],
                'cursor' => [
                    'name' => 'Cursor',
                    'where' => 'In .cursor/mcp.json. Restart Cursor after setting the variable.',
                ],
                'vscode' => [
                    'name' => 'VS Code',
                    'where' => 'In .vscode/mcp.json. VS Code asks for the token on first use and keeps it in its secret store.',
                ],
                'claude_desktop' => [
                    'name' => 'Claude Desktop',
                    'where' => 'In claude_desktop_config.json (Settings, Developer, Edit config), with your token in place of the placeholder. This file holds the token itself: keep it out of any repository. Needs Node.js.',
                ],
            ],
        ],
        'empty' => 'No tokens yet',
        'name' => 'Name',
        'projects' => 'Projects',
        // A token that reaches every project of the team
        'all_projects' => 'All projects (:count)',
        'permissions' => 'Permissions',
        'created_by' => 'Created by',
        'inactive' => 'Stopped',
        'inactive_hint' => 'Its creator no longer administers this team, so the token no longer works. Revoke it and make a new one.',
        'last_used' => 'Last used',
        'never_used' => 'Never',
        'expires' => 'Expires',
        'expires_in' => '{1} In 1 day|[2,*] In :count days',
        'never' => 'Never',
        'no_projects' => 'The team has no projects yet.',
        'new' => 'New token',
        // Button
        'create' => 'Create token',
        // Button
        'revoke' => 'Revoke',
        'revoke_question' => 'Revoke :name? Agents using it lose access at once.',
        // Button; opens the permission editor for a token
        'edit' => 'Edit',
        'tool_count' => '{1} 1 tool|[2,*] :count tools',
        'editor' => [
            'create_title' => 'New MCP token',
            'edit_title' => 'Edit :name',
            'acts_as_you' => 'Acts as you, within the permissions below. It can never do more than you can.',
            'all_projects' => 'All projects',
            'selected_projects' => 'Selected projects',
            'all_projects_hint' => 'Covers all :count projects today and any project created later. New projects get the Default permissions.',
            'selected_projects_hint' => 'Only the projects picked. Projects created later are not included.',
            'permissions_for' => 'Permissions for',
            'default' => 'Default',
            'default_hint' => 'Default applies to every project the token covers. Open a project to give it more or less.',
            'project_hint' => 'Changes for :project only. Anything not changed here follows Default. Team areas are set under Default.',
            'start_from' => 'Start from',
            'custom' => 'Custom',
            'presets' => [
                'read_only' => 'Read only',
                'triage' => 'Triage',
                'monitoring' => 'Monitoring setup',
                'screens' => 'Screen designer',
                'everything' => 'Everything',
            ],
            // How much a token may do in an area; each includes the ones before it
            'levels' => ['none' => 'None', 'read' => 'Read', 'write' => 'Write', 'manage' => 'Manage'],
            'find_tool' => 'Find a tool',
            'find_tool_placeholder' => 'e.g. delete, health, alert',
            'no_tool_matches' => 'No tool matches ":query".',
            'area' => 'Area',
            'set_all' => 'Set every area to :level',
            'access' => ':area access',
            'team_wide' => 'team',
            'team_wide_hint' => 'Team-wide: set under Default',
            'not_offered' => 'Not available for this area',
            'follows_default' => 'Follows Default',
            'level_changed' => 'Level changed, Default is :level',
            'tools_changed' => '{1} 1 tool changed|[2,*] :count tools changed',
            // Button; drops a project's level change for one area
            'reset' => 'Reset',
            // Button; drops single-tool changes in one area
            'reset_tools' => 'Reset tools',
            'show_tools' => 'Tools, :on of :total on',
            'hide_tools' => 'Hide tools',
            'added' => 'Added',
            'removed' => 'Removed',
            'warn_deletes' => 'Can delete data in this area.',
            'warn_sends_out' => 'Can send messages outside Lumberjack.',
            'warn_public' => 'Can make things public.',
            'warn_no_read' => 'Has change tools but no read tools, so the agent cannot look up what to change.',
            'tag_deletes' => 'deletes',
            'tag_sends_out' => 'sends out',
            'tag_public' => 'public',
            'can_use' => 'This token can use',
            'summary_default' => 'Default',
            'summary_per_project' => 'Per project',
            'summary_projects' => 'Projects',
            'summary_tools' => 'Tools',
            'summary_all_projects' => 'All projects, new ones included',
            'flag_deletes' => '{1} Can delete: 1 tool removes data for good.|[2,*] Can delete: :count tools remove data for good.',
            'flag_sends_out' => 'Sends outside Lumberjack: :tools.',
            'flag_public' => 'Can make things public: :tools.',
            'flag_new_projects' => 'New projects are included automatically, with the Default permissions.',
            'flag_team_wide' => 'Team areas apply to the whole team, not only the projects picked.',
            'flag_no_projects' => 'Pick at least one project.',
            'flag_read_only' => 'Read access only. Nothing can be changed.',
            // Button
            'save' => 'Save token',
            // Button
            'cancel' => 'Cancel',
            'secret_once' => 'The token is shown once. Permissions can be changed later without making a new token.',
        ],
    ],
    'retention' => [
        'title' => 'Team retention',
        'description' => 'Default and maximum for every project of the team. Empty uses the application value; each value is capped by the application.',
        // Save bar on the team and application retention pages
        'unsaved' => 'Unsaved changes',
        // Button; resets the form to the saved values
        'discard' => 'Discard',
    ],
    'create' => [
        'title' => 'Create a team',
        'description' => 'A new team starts empty, with you as the owner.',
        'name' => 'Team name',
        // Button
        'submit' => 'Create team',
    ],
];

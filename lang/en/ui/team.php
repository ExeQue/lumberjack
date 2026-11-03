<?php

declare(strict_types=1);

return [
    'title' => 'Members and tokens',
    'none' => [
        'title' => 'Not in a team yet',
        'body' => 'You join a team when someone invites you to it. Ask a team owner or an administrator of this Lumberjack for an invitation; it arrives by email.',
    ],
    'heading' => 'Team: :team',
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
        'title' => 'MCP tokens',
        'description' => 'Gives an agent access to the selected projects through MCP.',
        'endpoint' => 'Endpoint:',
        'empty' => 'No tokens yet',
        'name' => 'Name',
        'projects' => 'Projects',
        'scope' => 'Scope',
        'created_by' => 'Created by',
        'last_used' => 'Last used',
        'never_used' => 'Never',
        'expires' => 'Expires',
        'expires_in' => '{1} In 1 day|[2,*] In :count days',
        'never' => 'Never',
        'scopes' => ['read' => 'read', 'write' => 'write'],
        'scope_hints' => [
            'read' => 'read only',
            'write' => 'read, notes, status, assignment and repository issues',
        ],
        'no_projects' => 'The team has no projects yet.',
        'new' => 'New token',
        // Button
        'create' => 'Create token',
        // Button
        'revoke' => 'Revoke',
        'revoke_question' => 'Revoke :name? Agents using it lose access at once.',
    ],
    'retention' => [
        'title' => 'Team retention',
        'description' => 'Default and maximum for every project of the team. Empty uses the application value; each value is capped by the application.',
    ],
    'create' => [
        'title' => 'Create a team',
        'description' => 'A new team starts empty, with you as the owner.',
        'name' => 'Team name',
        // Button
        'submit' => 'Create team',
    ],
];

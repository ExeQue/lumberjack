<?php

declare(strict_types=1);

return [
    'nav' => [
        'section' => 'Administration',
        'teams' => 'All teams',
        'users' => 'All users',
        'audit' => 'Audit log',
    ],
    'teams' => [
        'title' => 'All teams',
        'description' => 'Every team on this Lumberjack. As an administrator you can open any of them.',
        'name' => 'Team',
        'owner' => 'Owner',
        'members' => 'Members',
        'projects' => 'Projects',
        'errors_24h' => 'Errors 24h',
        'created' => 'Created',
        'empty' => 'No teams yet',
        // Button
        'open' => 'Open',
        // Button
        'delete' => 'Delete',
        'delete_question' => 'Delete :name with all its projects and data?',
        'new' => 'New team',
        'owner_email' => 'Owner',
        'owner_hint' => 'Someone without an account is invited to own the team.',
        // Button
        'create' => 'Create team',
    ],
    'users' => [
        'title' => 'All users',
        'description' => 'Everyone with an account. New people join by invitation only.',
        'search' => 'Search by name or email',
        'name' => 'Name',
        'email' => 'Email',
        'teams' => 'Teams',
        'status' => 'Status',
        'joined' => 'Joined',
        'empty' => 'No users match',
        'you' => '(you)',
        'badges' => [
            'admin' => 'Administrator',
            'suspended' => 'Suspended',
            'password_change' => 'Must change password',
        ],
        // Buttons
        'make_admin' => 'Make administrator',
        'revoke_admin' => 'Revoke administrator',
        'revoke_admin_question' => 'Take administration away from :name?',
        'suspend' => 'Suspend',
        'suspend_question' => 'Suspend :name? They are signed out at once.',
        'reinstate' => 'Reinstate',
        'require_password_change' => 'Require new password',
        'require_password_change_question' => 'Make :name choose a new password before anything else?',
        'send_reset' => 'Send reset link',
        'invite' => [
            'title' => 'Invite someone',
            'description' => 'The invitation makes them a member of the chosen team.',
            'email' => 'Email',
            'team' => 'Team',
            'role' => 'Role',
            // Button
            'send' => 'Send invitation',
        ],
    ],
    'audit' => [
        'title' => 'Audit log',
        'description' => 'What administrators have done on this Lumberjack.',
        'when' => 'When',
        'admin' => 'Administrator',
        'action' => 'Action',
        'subject' => 'Subject',
        'details' => 'Details',
        'empty' => 'Nothing yet',
        'actions' => [
            'team_created' => 'Created team',
            'team_deleted' => 'Deleted team',
            'team_opened' => 'Opened team',
            'user_suspended' => 'Suspended user',
            'user_reinstated' => 'Reinstated user',
            'admin_granted' => 'Granted administration',
            'admin_revoked' => 'Revoked administration',
            'password_reset_sent' => 'Sent password reset',
            'password_change_required' => 'Required password change',
            'user_invited' => 'Invited user',
        ],
    ],
];

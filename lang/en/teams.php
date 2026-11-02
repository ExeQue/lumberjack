<?php

declare(strict_types=1);

return [
    'personal_team_name' => ":name's team",
    'roles' => ['owner' => 'Owner', 'admin' => 'Admin', 'member' => 'Member'],
    'invitation' => [
        'subject' => 'You are invited to :team on Lumberjack',
        'body' => 'You have been invited to join :team as :role.',
        'action' => 'Accept invitation',
        'expires' => 'The invitation expires in :days days.',
    ],
    'invitation_sent' => 'Invitation sent.',
    'member_removed' => 'Member removed.',
    'ownership_transferred' => 'Ownership transferred.',
];

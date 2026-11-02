<?php

declare(strict_types=1);

return [
    'roles' => ['owner' => 'Owner', 'admin' => 'Admin', 'member' => 'Member'],
    'invitation' => [
        'subject' => 'You are invited to :team on Lumberjack',
        'body' => 'You have been invited to join :team as :role.',
        'action' => 'Accept invitation',
        'expires' => 'The invitation expires in :days days.',
    ],
    'invitation_sent' => 'Invitation sent.',
    'transfer_before_deleting' => 'You own :team, which others are in. Make one of them its owner before deleting your account.',
    'invitation_wrong_account' => 'This invitation is for :email. Sign out and accept it with that address.',
    'member_removed' => 'Member removed.',
    'ownership_transferred' => 'Ownership transferred.',
];

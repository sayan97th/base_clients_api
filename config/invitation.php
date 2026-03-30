<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Invitation Expiry
    |--------------------------------------------------------------------------
    |
    | Number of days before a staff/admin invitation expires.
    | Override via INVITATION_EXPIRES_DAYS in your .env file.
    |
    */
    'expires_days' => (int) env('INVITATION_EXPIRES_DAYS', 7),
];

<?php

// Only what differs from Laravel's defaults; the framework merges the rest in.
return [

    // Minutes a verification link stays valid. Faculty often open the email later in the day, so it lasts a day
    // rather than Laravel's 60 minutes.
    'verification' => [
        'expire' => 1440,
    ],

    // The first admin's password, used only when AdminUserSeeder creates that account.
    'admin_seed_password' => env('ADMIN_SEED_PASSWORD'),

];

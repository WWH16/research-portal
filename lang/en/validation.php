<?php

// Only the messages changed from Laravel's defaults. Laravel merges this file over its own, key by key at the top level,
// so 'password' must list every Password rule message.

return [

    'password' => [
        'letters' => 'Include at least one letter.',
        'mixed' => 'Use both uppercase and lowercase letters.',
        'numbers' => 'Include at least one number.',
        'symbols' => 'Include at least one symbol, like ! or #.',
        'uncompromised' => 'This password has appeared in a data breach. Choose a different one.',
    ],

    'custom' => [
        'email' => [
            'required' => 'Enter an email address.',
            'email' => 'Enter a valid email address.',
            'unique' => 'An account with this email already exists.',
        ],
        'password' => [
            'required' => 'Enter a password.',
            'min' => 'Use at least :min characters.',
        ],
        'password_confirmation' => [
            'required' => 'Confirm your password.',
            'same' => 'Passwords don\'t match.',
        ],
    ],

];

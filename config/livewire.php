<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Temporary File Uploads
    |--------------------------------------------------------------------------
    |
    | Livewire checks every upload against these rules before the component
    | sees it, and its default caps files at 12 MB. Documents may be up to
    | 25 MB, so the cap here matches the rule in submissions/create.
    | Keys left out fall back to Livewire's defaults.
    |
    */

    'temporary_file_upload' => [
        'rules' => ['required', 'file', 'max:25600'],
    ],

];

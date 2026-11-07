<?php

/*
|--------------------------------------------------------------------------
| Invendis (proof of delivery) extension configuration
|--------------------------------------------------------------------------
|
| Design: docs/proof-of-delivery/DESIGN.md, AGRI-INPUTS.md and
| REPORTING-AUDIT.md in the Fleetbase repository.
|
*/

return [
    /*
    |--------------------------------------------------------------------------
    | Proof guard (DESIGN.md §4.3 and §14)
    |--------------------------------------------------------------------------
    |
    | Checks the public API's order activity updates (the endpoint the
    | Navigator driver app uses). An activity that needs proof of delivery is
    | refused without it.
    |
    |   off     - no check
    |   log     - write what would have been refused to the log, allow all
    |   enforce - refuse with HTTP 422
    |
    | Start with `log`, review the log, then switch to `enforce`.
    */
    'guard' => [
        'mode' => env('POD_GUARD_MODE', 'log'),
        'log_channel' => env('POD_GUARD_LOG_CHANNEL', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Country profiles (AGRI-INPUTS.md §16.1)
    |--------------------------------------------------------------------------
    |
    | Sierra Leone is the default. Labels and lists are starting values and
    | can be adjusted here without code changes.
    */
    'default_country' => env('POD_DEFAULT_COUNTRY', 'SL'),

    'countries' => [
        'SL' => [
            'name'         => 'Sierra Leone',
            'dial_code'    => '+232',
            'timezone'     => 'Africa/Freetown',
            'admin_levels' => [1 => 'Region', 2 => 'District', 3 => 'Chiefdom', 4 => 'Section'],
            'id_types'     => [
                'national_id'     => ['label' => 'National ID (NIN)', 'aliases' => ['nin', 'national id', 'national id card', 'national identity card', 'ncra', 'id card']],
                'voter_id'        => ['label' => 'Voter ID card', 'aliases' => ['voter', 'voters card', 'voter card', "voter's card", 'voter id', 'voters id', 'vic', 'nec card', 'ecsl card']],
                'passport'        => ['label' => 'Passport', 'aliases' => ['passport', 'international passport']],
                'drivers_licence' => ['label' => "Driver's licence", 'aliases' => ['driver licence', 'drivers licence', "driver's licence", 'driver license', 'drivers license', "driver's license", 'dl']],
                'other'           => ['label' => 'Other', 'aliases' => ['other']],
            ],
            'seed_file' => 'seed/sierra-leone/admin-areas.csv',
        ],
        'LR' => [
            'name'         => 'Liberia',
            'dial_code'    => '+231',
            'timezone'     => 'Africa/Monrovia',
            'admin_levels' => [1 => 'County', 2 => 'District', 3 => 'Clan', 4 => 'Town'],
            'id_types'     => [
                'national_id' => ['label' => 'National ID', 'aliases' => ['national id', 'nir', 'id card']],
                'voter_id'    => ['label' => 'Voter card', 'aliases' => ['voter', 'voter card', 'voters card', "voter's card", 'voter id']],
                'passport'    => ['label' => 'Passport', 'aliases' => ['passport']],
                'other'       => ['label' => 'Other', 'aliases' => ['other']],
            ],
            'seed_file' => null,
        ],
        'GH' => [
            'name'         => 'Ghana',
            'dial_code'    => '+233',
            'timezone'     => 'Africa/Accra',
            'admin_levels' => [1 => 'Region', 2 => 'District', 3 => 'Area council', 4 => 'Community'],
            'id_types'     => [
                'national_id' => ['label' => 'Ghana Card', 'aliases' => ['ghana card', 'national id', 'nia', 'id card']],
                'voter_id'    => ['label' => 'Voter ID', 'aliases' => ['voter', 'voter id', 'voters id', "voter's id", 'voter card']],
                'passport'    => ['label' => 'Passport', 'aliases' => ['passport']],
                'other'       => ['label' => 'Other', 'aliases' => ['other']],
            ],
            'seed_file' => null,
        ],
        'NG' => [
            'name'         => 'Nigeria',
            'dial_code'    => '+234',
            'timezone'     => 'Africa/Lagos',
            'admin_levels' => [1 => 'State', 2 => 'LGA', 3 => 'Ward', 4 => 'Community'],
            'id_types'     => [
                'national_id' => ['label' => 'NIN', 'aliases' => ['nin', 'national id', 'nimc', 'id card']],
                'voter_id'    => ['label' => "Voter's card (PVC)", 'aliases' => ['pvc', 'voter', 'voters card', "voter's card", 'voter card', 'voter id']],
                'passport'    => ['label' => 'Passport', 'aliases' => ['passport', 'international passport']],
                'other'       => ['label' => 'Other', 'aliases' => ['other']],
            ],
            'seed_file' => null,
        ],
        'GM' => [
            'name'         => 'The Gambia',
            'dial_code'    => '+220',
            'timezone'     => 'Africa/Banjul',
            'admin_levels' => [1 => 'Region', 2 => 'District', 3 => 'Village'],
            'id_types'     => [
                'national_id' => ['label' => 'National ID', 'aliases' => ['national id', 'id card']],
                'voter_id'    => ['label' => 'Voter card', 'aliases' => ['voter', 'voter card', 'voters card', "voter's card"]],
                'passport'    => ['label' => 'Passport', 'aliases' => ['passport']],
                'other'       => ['label' => 'Other', 'aliases' => ['other']],
            ],
            'seed_file' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Beneficiary import (AGRI-INPUTS.md §7.1, REPORTING-AUDIT.md §9)
    |--------------------------------------------------------------------------
    */
    'import' => [
        // Where uploaded lists are stored (any Laravel disk: local, s3 for MinIO...).
        'disk'                  => env('POD_IMPORT_DISK', env('FILESYSTEM_DRIVER', 'local')),
        // Key for the ID-number hash used to find duplicates. Back it up: changing it
        // means existing beneficiaries' IDs can no longer be compared with new lists.
        'id_hash_key'           => env('POD_ID_HASH_KEY', env('APP_KEY')),
        'max_rows'              => 50000,
        'min_age'               => 10,  // below: the row is rejected
        'warn_below_age'        => 15,  // below: a warning
        'max_age'               => 110,
        'name_similarity'       => 0.92, // Jaro-Winkler score for "likely duplicate" names
        'birth_year_tolerance'  => 2,
        'place_match_threshold' => 0.85,
    ],

    /*
    |--------------------------------------------------------------------------
    | Programme cards (AGRI-INPUTS.md §7.2)
    |--------------------------------------------------------------------------
    |
    | Card QR codes are signed with a key derived from this secret and the
    | programme. Keep it secret, and back it up: changing it invalidates every
    | card already printed.
    */
    'cards' => [
        'secret' => env('POD_CARD_SECRET', env('APP_KEY')),
    ],
];

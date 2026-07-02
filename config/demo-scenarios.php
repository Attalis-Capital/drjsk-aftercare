<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Demo Scenarios
    |--------------------------------------------------------------------------
    |
    | Each scenario defines a complete patient profile with clinical data
    | that gets seeded when a demo user selects it. This is a single-surgeon
    | plastic and reconstructive surgery practice, so the treating surgeon
    | (Dr James Southwell-Keely) is shared across all scenarios.
    |
    */

    'doctor' => [
        'name' => 'Dr James Southwell-Keely',
        'email' => 'dr.southwell-keely@demo.drjsk.com.au',
        'first_name' => 'James',
        'last_name' => 'Southwell-Keely',
        'npi' => '1234567890',
        'license_number' => 'MED0001234567',
        'medical_degree' => 'MBBS, FRACS (Plast)',
        'primary_specialty' => 'plastic_surgery',
        'secondary_specialties' => ['reconstructive_surgery'],
    ],

    'organization' => [
        'name' => 'Dr James Southwell-Keely Plastic Surgery',
        'type' => 'plastic_surgery',
        'address' => 'Suite 3, 156 Pacific Highway, St Leonards NSW 2065',
        'phone' => '(02) 9369 2800',
        'email' => 'reception@drjsk.com.au',
    ],

    'scenarios' => [

        'diep-flap' => [
            'key' => 'diep-flap',
            'name' => 'DIEP Flap Reconstruction',
            'description' => 'Day 5 recovery after a DIEP flap breast reconstruction. Flap healthy, one abdominal drain in place.',
            'icon' => 'heart',
            'color' => 'emerald',
            'specialty' => 'plastic_surgery',
            'featured' => true,
            'source_dir' => 'demo/visits/plastic-01-diep-flap',
            'visit' => [
                'visit_type' => 'office_visit',
                'class' => 'AMB',
                'service_type' => 'plastic_surgery_consultation',
                'reason_for_visit' => 'Day 5 review after right DIEP flap breast reconstruction',
                'summary' => 'Uncomplicated day 5 recovery after right DIEP flap reconstruction. Flap warm and well perfused. One abdominal drain in place. Continuing antibiotic prophylaxis, regular paracetamol, and DVT prophylaxis.',
                'duration_minutes' => 25,
                'days_ago' => 1,
            ],
            'chat_session' => [
                'topic' => 'Post-operative recovery: DIEP flap reconstruction',
            ],

            'weight_series' => [
                ['day' => -6, 'kg' => 68.4],
                ['day' => -5, 'kg' => 68.6],
                ['day' => -4, 'kg' => 68.5],
                ['day' => -3, 'kg' => 68.2],
                ['day' => -2, 'kg' => 68.1],
                ['day' => -1, 'kg' => 68.0],
            ],
        ],

        'abdominoplasty' => [
            'key' => 'abdominoplasty',
            'name' => 'Abdominoplasty (Mummy Makeover)',
            'description' => 'Day 4 recovery after an abdominoplasty with muscle repair. Two drains in place, compression garment on.',
            'icon' => 'scissors',
            'color' => 'blue',
            'specialty' => 'plastic_surgery',
            'featured' => true,
            'source_dir' => 'demo/visits/plastic-02-abdominoplasty',
            'visit' => [
                'visit_type' => 'office_visit',
                'class' => 'AMB',
                'service_type' => 'plastic_surgery_consultation',
                'reason_for_visit' => 'Day 4 review after abdominoplasty with rectus muscle repair',
                'summary' => 'Uncomplicated day 4 recovery after abdominoplasty with muscle repair. Wound healing well, two drains in place, compression garment on. Continuing antibiotic prophylaxis, regular paracetamol, and DVT prophylaxis.',
                'duration_minutes' => 20,
                'days_ago' => 1,
            ],
            'chat_session' => [
                'topic' => 'Post-operative recovery: abdominoplasty',
            ],

            'weight_series' => [
                ['day' => -4, 'kg' => 66.8],
                ['day' => -3, 'kg' => 66.6],
                ['day' => -2, 'kg' => 66.3],
                ['day' => -1, 'kg' => 66.0],
            ],
        ],

        'breast-reduction' => [
            'key' => 'breast-reduction',
            'name' => 'Breast Reduction / Mastopexy',
            'description' => 'Day 3 recovery after a bilateral breast reduction with lift. No drains used, dressings clean and dry.',
            'icon' => 'sparkles',
            'color' => 'amber',
            'specialty' => 'plastic_surgery',
            'featured' => true,
            'source_dir' => 'demo/visits/plastic-03-breast-reduction',
            'visit' => [
                'visit_type' => 'office_visit',
                'class' => 'AMB',
                'service_type' => 'plastic_surgery_consultation',
                'reason_for_visit' => 'Day 3 review after bilateral breast reduction with mastopexy',
                'summary' => 'Uncomplicated day 3 recovery after bilateral breast reduction with mastopexy. Both nipple-areola complexes healthy and well perfused. No drains used. Continuing antibiotic prophylaxis and regular paracetamol.',
                'duration_minutes' => 20,
                'days_ago' => 1,
            ],
            'chat_session' => [
                'topic' => 'Post-operative recovery: breast reduction and lift',
            ],

            'weight_series' => [
                ['day' => -3, 'kg' => 72.3],
                ['day' => -2, 'kg' => 72.1],
                ['day' => -1, 'kg' => 72.0],
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Practitioners
    |--------------------------------------------------------------------------
    |
    | Single-surgeon practice: all scenarios use the default 'doctor' config
    | (Dr James Southwell-Keely). No per-specialty practitioners are defined.
    |
    */

    'practitioners' => [],

];

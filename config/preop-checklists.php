<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pre-operative Checklist Templates (GENERIC - not AI-personalised)
    |--------------------------------------------------------------------------
    |
    | Generic, editable pre-operative preparation templates for the practice's
    | priority procedures. These are the same for every patient having a given
    | procedure - they are NOT personalised by the AI. The treating team can
    | edit these templates (see the admin editor). Individual patients tick
    | items off as they complete them; that tick state is stored per patient
    | on the device and is never used to alter the template itself.
    |
    | Each procedure has grouped sections. Each item may carry an optional
    | 'link' (drjsk.com.au or a YouTube video) for further guidance.
    |
    */

    'practice' => [
        'name' => 'Dr James Southwell-Keely Plastic Surgery',
        'phone' => '(02) 9369 2800',
        'website' => 'https://www.drjsk.com.au',
    ],

    'templates' => [

        'diep-flap' => [
            'key' => 'diep-flap',
            'name' => 'DIEP Flap Reconstruction',
            'summary' => 'How to prepare for your DIEP flap breast reconstruction.',
            'sections' => [
                [
                    'title' => 'Medications and supplements to stop',
                    'items' => [
                        ['label' => 'Stop fish oil, vitamin E, turmeric, and arnica 2 weeks before surgery (they can increase bleeding and bruising).', 'link' => null],
                        ['label' => 'Stop any blood-thinning medicine only as directed by your surgeon or GP - do not stop on your own.', 'link' => null],
                        ['label' => 'If you smoke, stop as early as possible before surgery - smoking reduces blood flow and can put the flap at risk.', 'link' => 'https://www.drjsk.com.au'],
                        ['label' => 'Keep taking your regular prescribed medicines unless told otherwise.', 'link' => null],
                    ],
                ],
                [
                    'title' => 'Fasting',
                    'items' => [
                        ['label' => 'No food for 6 hours before your arrival time.', 'link' => null],
                        ['label' => 'Clear fluids (water) are allowed up to 2 hours before, unless told otherwise.', 'link' => null],
                    ],
                ],
                [
                    'title' => 'What to bring',
                    'items' => [
                        ['label' => 'Your admission paperwork, Medicare card, and any private health details.', 'link' => null],
                        ['label' => 'A list of your current medicines and supplements.', 'link' => null],
                        ['label' => 'Loose, comfortable clothing that opens at the front.', 'link' => null],
                        ['label' => 'Toiletries and phone charger for a hospital stay of several nights.', 'link' => null],
                    ],
                ],
                [
                    'title' => 'Garments to purchase',
                    'items' => [
                        ['label' => 'A front-fastening, non-underwire supportive bra as advised by the practice.', 'link' => 'https://www.drjsk.com.au'],
                        ['label' => 'A soft abdominal binder if recommended for the donor site.', 'link' => null],
                    ],
                ],
                [
                    'title' => 'Post-op supplies for home',
                    'items' => [
                        ['label' => 'Simple pain relief (paracetamol) and any prescribed medicines filled in advance.', 'link' => null],
                        ['label' => 'Loose pillows to support the abdomen and chest when resting.', 'link' => null],
                        ['label' => 'A record sheet for drain output if you go home with a drain.', 'link' => null],
                    ],
                ],
                [
                    'title' => 'Transport and support',
                    'items' => [
                        ['label' => 'Arrange for someone to drive you home and stay with you for the first few days.', 'link' => null],
                        ['label' => 'You must not drive until your surgeon says it is safe.', 'link' => null],
                    ],
                ],
            ],
        ],

        'abdominoplasty' => [
            'key' => 'abdominoplasty',
            'name' => 'Abdominoplasty (Mummy Makeover)',
            'summary' => 'How to prepare for your abdominoplasty (tummy tuck).',
            'sections' => [
                [
                    'title' => 'Medications and supplements to stop',
                    'items' => [
                        ['label' => 'Stop fish oil, vitamin E, turmeric, and arnica 2 weeks before surgery (they can increase bleeding and bruising).', 'link' => null],
                        ['label' => 'Stop any blood-thinning medicine only as directed by your surgeon or GP.', 'link' => null],
                        ['label' => 'If you smoke, stop as early as possible before surgery to reduce wound-healing problems.', 'link' => 'https://www.drjsk.com.au'],
                        ['label' => 'Keep taking your regular prescribed medicines unless told otherwise.', 'link' => null],
                    ],
                ],
                [
                    'title' => 'Fasting',
                    'items' => [
                        ['label' => 'No food for 6 hours before your arrival time.', 'link' => null],
                        ['label' => 'Clear fluids (water) are allowed up to 2 hours before, unless told otherwise.', 'link' => null],
                    ],
                ],
                [
                    'title' => 'What to bring',
                    'items' => [
                        ['label' => 'Your admission paperwork, Medicare card, and any private health details.', 'link' => null],
                        ['label' => 'A list of your current medicines and supplements.', 'link' => null],
                        ['label' => 'Loose, high-waisted clothing that will not press on the wound.', 'link' => null],
                    ],
                ],
                [
                    'title' => 'Garments to purchase',
                    'items' => [
                        ['label' => 'A compression garment as advised by the practice, to wear day and night.', 'link' => 'https://www.drjsk.com.au'],
                    ],
                ],
                [
                    'title' => 'Post-op supplies for home',
                    'items' => [
                        ['label' => 'Simple pain relief (paracetamol) and any prescribed medicines filled in advance.', 'link' => null],
                        ['label' => 'Pillows to support a slightly bent-forward resting position.', 'link' => null],
                        ['label' => 'A record sheet for drain output.', 'link' => null],
                    ],
                ],
                [
                    'title' => 'Transport and support',
                    'items' => [
                        ['label' => 'Arrange for someone to drive you home and help at home for the first week.', 'link' => null],
                        ['label' => 'You must not drive until your surgeon says it is safe.', 'link' => null],
                    ],
                ],
            ],
        ],

        'breast-reduction' => [
            'key' => 'breast-reduction',
            'name' => 'Breast Reduction / Mastopexy',
            'summary' => 'How to prepare for your breast reduction and lift.',
            'sections' => [
                [
                    'title' => 'Medications and supplements to stop',
                    'items' => [
                        ['label' => 'Stop fish oil, vitamin E, turmeric, and arnica 2 weeks before surgery (they can increase bleeding and bruising).', 'link' => null],
                        ['label' => 'Stop any blood-thinning medicine only as directed by your surgeon or GP.', 'link' => null],
                        ['label' => 'If you smoke, stop as early as possible before surgery to reduce wound-healing problems.', 'link' => 'https://www.drjsk.com.au'],
                        ['label' => 'Keep taking your regular prescribed medicines unless told otherwise.', 'link' => null],
                    ],
                ],
                [
                    'title' => 'Fasting',
                    'items' => [
                        ['label' => 'No food for 6 hours before your arrival time.', 'link' => null],
                        ['label' => 'Clear fluids (water) are allowed up to 2 hours before, unless told otherwise.', 'link' => null],
                    ],
                ],
                [
                    'title' => 'What to bring',
                    'items' => [
                        ['label' => 'Your admission paperwork, Medicare card, and any private health details.', 'link' => null],
                        ['label' => 'A list of your current medicines and supplements.', 'link' => null],
                        ['label' => 'A loose, front-opening top to wear home.', 'link' => null],
                    ],
                ],
                [
                    'title' => 'Garments to purchase',
                    'items' => [
                        ['label' => 'A supportive, non-underwire surgical bra as advised by the practice, to wear day and night.', 'link' => 'https://www.drjsk.com.au'],
                    ],
                ],
                [
                    'title' => 'Post-op supplies for home',
                    'items' => [
                        ['label' => 'Simple pain relief (paracetamol) and any prescribed medicines filled in advance.', 'link' => null],
                        ['label' => 'Extra pillows to support your upper body when resting.', 'link' => null],
                    ],
                ],
                [
                    'title' => 'Transport and support',
                    'items' => [
                        ['label' => 'Arrange for someone to drive you home and help at home for the first few days.', 'link' => null],
                        ['label' => 'You must not drive until your surgeon says it is safe.', 'link' => null],
                    ],
                ],
            ],
        ],

    ],

];

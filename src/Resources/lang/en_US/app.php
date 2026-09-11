<?php

/*
 * Copyright (c) 2026 benelog GmbH & Co. KG
 * SPDX-License-Identifier: MIT
 */

return [
    'bridge' => [
        'unreachable' => 'The OpenEPCIS platform could not be reached. This is not a problem with your data.',
        'refused'     => 'The OpenEPCIS platform refused the request (:status).',
    ],

    'panel' => [
        'title'         => 'GS1 identity',
        'no-gtin'       => 'This product has no GTIN yet.',
        'draw'          => 'Draw the next GTIN',
        'drawing'       => 'Drawing…',
        'drawn'         => 'Drawn :value — save the product to keep it.',
        'batch'         => 'Batch',
        'asking'        => 'Asking the platform…',
        'gtin'          => 'GTIN',
        'status'        => 'Identity',
        'licensee'      => 'Registered to',
        'prefix'        => 'Company prefix',
        'checked'       => 'Last checked',
        'digital-link'  => 'Digital Link',
        'action'        => 'Needs attention',
        'action-yes'    => 'Yes — see the reason below',
        'action-no'     => 'No',
        'reason'        => 'Reason',
        'unverified'    => 'Not confirmed with GS1 yet. This usually means the registry was unreachable, not that the number is wrong.',
        'have' => 'I have a GTIN',
        'have-placeholder' => 'e.g. 04068194952013',
        'checking' => 'Checking …',
        'accepted' => ':value accepted — save the product to keep it.',
        'rejected' => 'That number was not accepted.',
        'foreign-prefix' => 'Foreign company prefix: the number is stored whole rather than composed from the SKU.',
        'valid' => 'valid',
        'own-prefix' => 'own prefix',
        'draw-failed' => 'No number could be drawn.',
        'stored-composed' => ':value accepted — the SKU now carries the article reference.',
        'stored-whole' => ':value accepted and stored whole.',
        'never-synced'  => 'Not published yet. The connector fills this in the first time it publishes this product.',
    ],

    'gpc' => [
        'label'       => 'GS1 classification (GPC)',
        'placeholder' => 'Search by words — "ground coffee", not 10000045',
        'searching'   => 'Searching…',
        'none'        => 'Nothing matched. Try fewer or more general words.',
        'hint'        => 'Stored as the eight-digit brick code.',
        'stored'      => 'Stored as :code. Search to change it.',
        'chosen'      => 'Selected: :title (:code)',
    ],

    'configuration' => [
        'gs1' => [
            'title'    => 'GS1 target market',
            'info'     => 'Which market a channel sells into, stated the way GS1 states it.',
            'settings' => [
                'title'                      => 'Target market per channel',
                'info'                       => 'A channel is the axis market-specific values hang off. GS1 calls that the target market.',
                'target-market'              => 'Target market countries',
                'target-market-placeholder'  => 'DE',
                'target-market-hint'         => 'ISO 3166-1 alpha-2 codes, comma-separated for a market of several countries. Published as gs1:targetMarket; a product that states its own keeps it.',
                'first-availability'         => 'First available from',
                'first-availability-hint'    => 'Optional. The date the goods may first be sold to consumers in this market (gs1:consumerFirstAvailabilityDateTime).',
            ],
        ],
    ],
];

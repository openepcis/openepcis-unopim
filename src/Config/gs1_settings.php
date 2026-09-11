<?php

/*
 * Copyright (c) 2026 benelog GmbH & Co. KG
 * SPDX-License-Identifier: MIT
 */

/*
 * The GS1 target market, declared where a market is already declared.
 *
 * A channel is the axis along which UnoPim varies market-specific values —
 * prices, names, descriptions all hang off it. GS1 has a term for what that
 * axis means: `gs1:targetMarket`, a `TargetMarketDetails` of the countries a
 * product is placed on the market in, together with the date it may first be
 * sold there (`gs1:consumerFirstAvailabilityDateTime`).
 *
 * So the two line up, and saying so once per channel is better than asking a
 * merchant to repeat the same country code on every product. A product that
 * genuinely differs still overrides it; everything else inherits the channel's.
 *
 * `channel_based` is UnoPim's own flag for a per-channel setting: the value is
 * stored with the channel code and read back with it.
 */
return [
    [
        'key'    => 'catalog.gs1.settings',
        'name'   => 'openepcis::app.configuration.gs1.settings.title',
        'info'   => 'openepcis::app.configuration.gs1.settings.info',
        'sort'   => 1,
        'fields' => [
            [
                'name'          => 'target_market_countries',
                'title'         => 'openepcis::app.configuration.gs1.settings.target-market',
                'type'          => 'text',
                'channel_based' => true,
                'placeholder'   => 'openepcis::app.configuration.gs1.settings.target-market-placeholder',
                'info'          => 'openepcis::app.configuration.gs1.settings.target-market-hint',
                // ISO 3166-1 alpha-2, which is what the GS1 term takes and what
                // the resolver stores. Several are allowed because one channel
                // may serve one market made of several countries.
                'validation'    => 'regex:/^[A-Za-z]{2}(\s*,\s*[A-Za-z]{2})*$/',
            ], [
                // Optional, and part of the same GS1 term rather than a second
                // idea: TargetMarketDetails is countries AND the date the goods
                // may first be sold there.
                'name'          => 'first_availability',
                'title'         => 'openepcis::app.configuration.gs1.settings.first-availability',
                'type'          => 'date',
                'channel_based' => true,
                'info'          => 'openepcis::app.configuration.gs1.settings.first-availability-hint',
            ],
        ],
    ],
];

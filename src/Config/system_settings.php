<?php

/*
 * Copyright (c) 2026 benelog GmbH & Co. KG
 * SPDX-License-Identifier: MIT
 */

/*
 * One row under UnoPim's existing Digital Product Passport section, rather
 * than a section of our own. The setting belongs to the passport surface a
 * merchant already knows, and a separate "OpenEPCIS" heading would ask them to
 * learn where we keep things.
 */
return [
    [
        'key'          => 'digital_product_passport.gs1',
        'name'         => 'openepcis::app.configuration.gs1.title',
        'info'         => 'openepcis::app.configuration.gs1.info',
        'icon'         => 'icon-setting',
        'config_group' => 'catalog.gs1.settings',
        'acl'          => 'configuration.system_settings.product_passport',
        'sort'         => 2,
    ],
];

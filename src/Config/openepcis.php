<?php

/*
 * Copyright (c) 2026 benelog GmbH & Co. KG
 * SPDX-License-Identifier: MIT
 */

return [
    /*
     * Where the connector's bridge answers.
     *
     * Inside a cluster this is the service name and plain HTTP: the call never
     * leaves the namespace, and giving it a public hostname would mean routing
     * an API that answers with a tenant's own credentials out onto the
     * internet for no gain.
     */
    'bridge_url' => env('OPENEPCIS_BRIDGE_URL', 'http://openepcis-connectors:8080'),

    /*
     * Which connection in the connector's configuration this UnoPim is.
     *
     * The bridge answers for a tenant using that tenant's deposited
     * credentials, so this decides which catalog is asked and which company
     * prefix a drawn number comes from. Getting it wrong does not fail
     * quietly: the bridge answers 404 for a tenant it does not have.
     */
    'tenant' => env('OPENEPCIS_TENANT', 'pim1'),

    /*
     * How long to wait for the bridge, in seconds.
     *
     * Short on purpose. Everything here sits in front of somebody typing, and
     * a classification search that takes four seconds is one nobody uses. A
     * timeout is shown as "could not reach the platform", never as "no
     * results" — the difference decides whether a merchant types a code by
     * hand to get past it.
     */
    'timeout' => (float) env('OPENEPCIS_BRIDGE_TIMEOUT', 4),

    /*
     * Das Ziehen einer Nummer wartet laenger, weil GS1 laenger braucht: der
     * Schluesselvorrat wird beantwortet, indem die gesamte Basisnummer sortiert
     * wird — gemessen ~12 Sekunden. Ein Urteil kommt in 27 ms und behaelt
     * deshalb das kurze Limit oben; ein Formular, das die Seite ohnehin neu
     * laedt, darf warten.
     */
    'allocate_timeout' => (float) env('OPENEPCIS_BRIDGE_ALLOCATE_TIMEOUT', 90),

    /*
     * The attribute codes this package reads and writes. They match the ones
     * the Java side maintains; changing one here without changing it there
     * leaves the panel reading an attribute nobody fills in.
     */
    /*
     * How this installation arrives at a GTIN. Must match the connection's
     * `source.options.gtin-from`, or the button fills a field the connector
     * does not read.
     *
     *   composed  the SKU is the article reference; the key is the company
     *             prefix, that reference and a check digit. One short number
     *             maintained, the key derived — and the check digit cannot be
     *             typed wrong because nobody types it.
     *   sku       the SKU already is the full GTIN, as for goods bought in
     *             with somebody else's EAN.
     *   attribute the key lives in its own field; name it in `attributes.gtin`.
     */
    'identifier_mode' => env('OPENEPCIS_IDENTIFIER_MODE', 'composed'),

    'attributes' => [
        /*
         * Where this installation keeps its GTIN. Two answers are both right:
         * many merchants keep an internal article number as the SKU and the GS1
         * key in UnoPim's own passport field, while in retail the SKU often IS
         * the EAN and a second attribute holding the same digits is a copy
         * waiting to disagree with itself.
         *
         * Defaults to the SKU, which for most catalogues is what it already
         * is. The literal `sku` means the SKU field; anything else is an
         * attribute code. Whatever is set here must match the connection's
         * `source.options.gtin-from`, or the panel reads one field while the
         * connector publishes another.
         */
        /*
         * Where the panel READS the key. With the default identifier mode the
         * connector composes it and writes it here, so this is where it can be
         * seen; with mode `sku` it is the SKU field.
         */
        'gtin' => env('OPENEPCIS_GTIN_ATTRIBUTE', 'dpp_gtin'),

        /* UnoPim's own passport field for a batch, role Batch. */
        'batch' => env('OPENEPCIS_BATCH_ATTRIBUTE', 'dpp_batch_identifier'),

        /*
         * The two things GS1 needs that UnoPim has no field for. Both are
         * input: a person chooses them.
         */
        'gpc'           => 'ddm_gpc_code',
        'target_market' => 'ddm_target_market',

        /*
         * The one thing the connector writes back, and the only one worth
         * storing: whether there is something here for a person to fix.
         *
         * Deliberately not the identity status, which reads "unverified" when
         * the GS1 registry was merely unreachable — a merchant taking that for
         * a data error goes looking for a fault that is ours. Everything else
         * the panel asks the bridge for at the moment somebody looks, which is
         * a better answer than one written last month.
         */
        'action_required' => 'ddm_action_required',
    ],
];

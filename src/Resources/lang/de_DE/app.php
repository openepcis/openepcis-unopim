<?php

/*
 * Copyright (c) 2026 benelog GmbH & Co. KG
 * SPDX-License-Identifier: MIT
 */

return [
    'bridge' => [
        'unreachable' => 'Die OpenEPCIS-Plattform war nicht erreichbar. Das liegt nicht an Ihren Daten.',
        'refused'     => 'Die OpenEPCIS-Plattform hat die Anfrage abgelehnt (:status).',
    ],

    'panel' => [
        'title'         => 'GS1-Identität',
        'no-gtin'       => 'Dieses Produkt hat noch keine GTIN.',
        'draw'          => 'Nächste GTIN ziehen',
        'drawing'       => 'Wird gezogen …',
        'drawn'         => ':value gezogen — zum Übernehmen das Produkt speichern.',
        'batch'         => 'Charge',
        'asking'        => 'Wird bei der Plattform erfragt …',
        'gtin'          => 'GTIN',
        'status'        => 'Identität',
        'licensee'      => 'Registriert auf',
        'prefix'        => 'Basisnummer',
        'checked'       => 'Zuletzt geprüft',
        'digital-link'  => 'Digital Link',
        'action'        => 'Zu tun',
        'action-yes'    => 'Ja — Grund siehe unten',
        'action-no'     => 'Nichts',
        'reason'        => 'Grund',
        'unverified'    => 'Noch nicht bei GS1 bestätigt. Das heißt in aller Regel, dass die Registry nicht erreichbar war — nicht, dass die Nummer falsch ist.',
        'have' => 'Ich habe eine GTIN',
        'have-placeholder' => 'z. B. 04068194952013',
        'checking' => 'Wird geprüft …',
        'accepted' => ':value übernommen — zum Speichern das Produkt sichern.',
        'rejected' => 'Diese Nummer wurde nicht angenommen.',
        'foreign-prefix' => 'Fremde Basisnummer: die Nummer wird ganz gespeichert, nicht aus der SKU zusammengesetzt.',
        'valid' => 'gültig',
        'own-prefix' => 'eigene Basisnummer',
        'draw-failed' => 'Es konnte keine Nummer gezogen werden.',
        'stored-composed' => ':value übernommen — die SKU trägt jetzt die Artikelnummer.',
        'stored-whole' => ':value übernommen und ganz gespeichert.',
        'never-synced'  => 'Noch nicht veröffentlicht. Der Connector trägt das beim ersten Veröffentlichen ein.',
    ],

    'gpc' => [
        'label'       => 'GS1-Warengruppe (GPC)',
        'placeholder' => 'Mit Worten suchen — „gemahlener Kaffee“, nicht 10000045',
        'searching'   => 'Wird gesucht …',
        'none'        => 'Kein Treffer. Weniger oder allgemeinere Worte versuchen.',
        'hint'        => 'Gespeichert wird der achtstellige Brick-Code.',
        'stored'      => 'Gespeichert als :code. Zum Ändern suchen.',
        'chosen'      => 'Gewählt: :title (:code)',
    ],

    'configuration' => [
        'gs1' => [
            'title'    => 'GS1-Zielmarkt',
            'info'     => 'In welchen Markt ein Kanal verkauft — als GS1-Angabe am Produkt.',
            'settings' => [
                'title'                      => 'Zielmarkt je Kanal',
                'info'                       => 'Ein Kanal ist die Achse, an der marktabhängige Werte hängen. GS1 nennt das den Zielmarkt.',
                'target-market'              => 'Zielmarktländer',
                'target-market-placeholder'  => 'DE',
                'target-market-hint'         => 'Ländercodes nach ISO 3166-1 alpha-2, mehrere durch Komma getrennt. Wird als gs1:targetMarket veröffentlicht; ein Produkt mit eigener Angabe behält sie.',
                'first-availability'         => 'Erstmals erhältlich ab',
                'first-availability-hint'    => 'Optional. Das Datum, ab dem die Ware in diesem Markt an Verbraucher verkauft werden darf (gs1:consumerFirstAvailabilityDateTime).',
            ],
        ],
    ],
];

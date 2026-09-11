<?php

/*
 * Copyright (c) 2026 benelog GmbH & Co. KG
 * SPDX-License-Identifier: MIT
 */

use Illuminate\Support\Facades\Route;
use OpenEPCIS\UnoPim\Http\Controllers\ChannelMarketController;
use OpenEPCIS\UnoPim\Http\Controllers\IdentityController;
use OpenEPCIS\UnoPim\Http\Controllers\IdentityWriteController;
use OpenEPCIS\UnoPim\Http\Controllers\PassportTemplateController;

/*
 * Behind UnoPim's own admin session, not open to the network.
 *
 * The `admin` middleware and the configured admin prefix are UnoPim's own
 * convention for this; `web` comes from the provider, as it does for the
 * built-in admin routes.
 *
 * These proxy a bridge that answers using a tenant's deposited credentials.
 * Exposing them to anyone who can reach the instance would hand out a
 * customer's classification lookups and, worse, their number range.
 */
Route::group([
    'prefix'     => config('app.admin_url').'/openepcis',
    'middleware' => ['admin'],
    'as'         => 'openepcis.',
], function () {
    Route::get('gpc', [IdentityController::class, 'gpc'])->name('gpc');
    Route::post('allocate', [IdentityController::class, 'allocate'])->name('allocate');
    Route::get('resolve', [IdentityController::class, 'resolve'])->name('resolve');

    // Formular-Posts statt fetch: das Panel wird nachträglich in das
    // Produktformular eingehängt, und ein so eingefügtes <script> führt der
    // Browser nie aus — die Knöpfe taten wortlos nichts. Ein Formular hat das
    // Problem nicht, behält UnoPims CSRF-Schutz und spart den zweiten Schritt.
    Route::post('products/{id}/draw', [IdentityWriteController::class, 'draw'])
        ->name('draw');
    Route::post('products/{id}/deposit', [IdentityWriteController::class, 'deposit'])
        ->name('deposit');
});

/*
 * The passport template, on the REST API rather than behind the admin session.
 *
 * This one is read by the connector, not by a browser, so it sits where the
 * connector already authenticates: the same API, the same token, the same
 * per-user permissions UnoPim applies to everything else there.
 */
Route::group([
    'prefix'     => 'api/v1/rest/openepcis',
    'middleware' => ['api', 'auth:api'],
    'as'         => 'openepcis.api.',
], function () {
    Route::get('passport-templates', [PassportTemplateController::class, 'index'])
        ->name('passport-templates');
    Route::get('channel-markets', [ChannelMarketController::class, 'index'])
        ->name('channel-markets');
});

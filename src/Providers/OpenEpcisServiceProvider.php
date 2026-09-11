<?php

/*
 * Copyright (c) 2026 benelog GmbH & Co. KG
 * SPDX-License-Identifier: MIT
 */

namespace OpenEPCIS\UnoPim\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use OpenEPCIS\UnoPim\Bridge;
use Webkul\Theme\ViewRenderEventManager;

/**
 * Registers the package with UnoPim.
 *
 * <p>Found by Laravel's package discovery, so an installation is a composer
 * require and nothing else — no entry to add to a config file that an upgrade
 * would later overwrite.
 */
class OpenEpcisServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('web')->group(__DIR__.'/../Routes/admin.php');

        // UnoPim names its language directories with the full tag — en_US, de_DE
        // — and its configured locale is one of those, so `en` is never looked
        // at. A missing translation is not an error in Laravel: it renders the
        // key, so the screen fills with openepcis::app.panel.title and nothing
        // anywhere says why.
        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'openepcis');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'openepcis');

        $this->publishes([
            __DIR__.'/../Config/openepcis.php' => config_path('openepcis.php'),
        ], 'openepcis-config');

        // Pushed into UnoPim's own product form rather than given a screen of
        // its own. Identity is not a separate task a merchant remembers to do;
        // it is a property of the product they are already editing, and a
        // second screen is one nobody opens.
        //
        // Through UnoPim's own view_render_event rather than by overriding a
        // template: an override is a copy of somebody else's file that stops
        // matching it at the next upgrade, silently.
        Event::listen(
            'unopim.admin.catalog.product.edit.form.before',
            function (ViewRenderEventManager $manager) {
                $manager->addTemplate('openepcis::product.panel');
                $manager->addTemplate('openepcis::product.gpc');
            }
        );
    }

    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([\OpenEPCIS\UnoPim\Console\InstallPassportTemplate::class]);
        }

        $this->mergeConfigFrom(__DIR__.'/../Config/openepcis.php', 'openepcis');

        // The GS1 target market, as a per-channel setting in UnoPim's own
        // configuration screen. Two merges because UnoPim keeps the field
        // definitions and the navigation row in different config trees.
        $this->mergeConfigFrom(__DIR__.'/../Config/gs1_settings.php', 'core');
        $this->mergeConfigFrom(__DIR__.'/../Config/system_settings.php', 'system_settings');

        $this->app->singleton(Bridge::class);
    }
}

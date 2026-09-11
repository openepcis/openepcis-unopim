<?php

/*
 * Copyright (c) 2026 benelog GmbH & Co. KG
 * SPDX-License-Identifier: MIT
 */

namespace OpenEPCIS\UnoPim\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use OpenEPCIS\UnoPim\Bridge;
use Webkul\Product\Models\ProductProxy;

/**
 * Giving a product its number — drawn or brought along.
 *
 * <p>Both are plain form posts, and that is the point. The panel is injected
 * into the product form through UnoPim's `view_render_event`, and a
 * {@code <script>} that arrives that way is never executed: the browser runs
 * inline scripts while parsing, not when a node is inserted afterwards. The
 * buttons therefore sat on the page doing nothing, silently — no error, no
 * console message, just a click that led nowhere.
 *
 * <p>A form has no such problem. It also survives without JavaScript, keeps
 * the CSRF protection UnoPim already applies, and leaves one round trip where
 * there were two: the number is written and saved rather than typed into a
 * field somebody still has to remember to save.
 */
class IdentityWriteController extends Controller
{
    public function __construct(private readonly Bridge $bridge) {}

    /** "Give me one." */
    public function draw(Request $request, int $id): RedirectResponse
    {
        $answer = $this->bridge->allocate('01');

        if (! ($answer['ok'] ?? false)) {
            // Said as it came back. "The range is exhausted" and "the platform
            // is unreachable" need different things done about them, and one
            // wording for both costs an afternoon.
            return back()->with('error', $answer['error'] ?? trans('openepcis::app.panel.draw-failed'));
        }

        $key = $answer['results']['key'] ?? $answer['results']['value'] ?? null;
        $gcp = $answer['results']['gcp'] ?? null;

        if ($key === null) {
            return back()->with('error', trans('openepcis::app.panel.draw-failed'));
        }

        return $this->store($id, (string) $key, $gcp === null ? null : (string) $gcp);
    }

    /** "I have one." */
    public function deposit(Request $request, int $id): RedirectResponse
    {
        $typed = preg_replace('/[\s-]/', '', (string) $request->input('gtin', ''));

        if ($typed === '') {
            return back();
        }

        $answer = $this->bridge->resolve('01', $typed, (string) $request->input('sku', ''));

        if (! ($answer['ok'] ?? false)) {
            return back()->with('error', $answer['error'] ?? trans('openepcis::app.panel.rejected'));
        }

        $verdict = $answer['results']['verdicts'][0] ?? null;

        // The platform explains what is wrong with a number far better than a
        // regular expression here could: a bad check digit, a length that is
        // not a GTIN, a prefix nobody licenses. Refusing with its own words
        // means the merchant reads one explanation rather than two.
        if ($verdict === null || ($verdict['dataProblem'] ?? false)) {
            return back()->with('error', $verdict['reason'] ?? trans('openepcis::app.panel.rejected'));
        }

        $key = (string) ($verdict['canonicalKey'] ?? $typed);
        $gcp = ($verdict['gcpOwnership'] ?? '') === 'own_prefix' && ($verdict['gcpLength'] ?? null)
            ? substr(str_pad($key, 14, '0', STR_PAD_LEFT), 1, (int) $verdict['gcpLength'])
            : null;

        return $this->store($id, $key, $gcp);
    }

    /**
     * Writes the key where this installation reads it from.
     *
     * <p>Under the tenant's own prefix the key decomposes, so the SKU carries
     * the article reference and the connector composes the number back — the
     * product then keeps ONE place where its identity lives. A supplier's key
     * cannot be decomposed that way and is stored whole.
     */
    private function store(int $id, string $key, ?string $gcp): RedirectResponse
    {
        $product = ProductProxy::modelClass()::find($id);

        if ($product === null) {
            return back()->with('error', trans('openepcis::app.panel.rejected'));
        }

        $values = $product->values ?? [];
        $composed = config('openepcis.identifier_mode') === 'composed' && $gcp !== null;

        if ($composed) {
            $fourteen = str_pad($key, 14, '0', STR_PAD_LEFT);
            $reference = substr($fourteen, 1 + strlen($gcp), -1);
            $product->sku = $reference;
            $values['common']['sku'] = $reference;
        } else {
            $values['common'][config('openepcis.attributes.gtin', 'dpp_gtin')] = $key;
        }

        $product->values = $values;
        $product->save();

        return back()->with('success', trans(
            $composed ? 'openepcis::app.panel.stored-composed' : 'openepcis::app.panel.stored-whole',
            ['value' => $key]
        ));
    }
}

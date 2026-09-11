<?php

/*
 * Copyright (c) 2026 benelog GmbH & Co. KG
 * SPDX-License-Identifier: MIT
 */

namespace OpenEPCIS\UnoPim\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Webkul\ProductPassport\Models\PassportTemplate;

/**
 * Publishes UnoPim's passport templates over its REST API.
 *
 * UnoPim models a Digital Product Passport properly — sections, fields, access
 * tiers, and an identifier role that says which attribute carries the GTIN, the
 * model identifier and the batch. Its own words on that role are the whole
 * reason this endpoint exists:
 *
 *   "Any attribute can play the role — if your GTIN lives in `ean`, point the
 *    role at `ean`."
 *
 * So the merchant has already told UnoPim where their identifiers live and what
 * their passport contains. Asking them to say it a second time in a connector's
 * mapping file is asking them to keep two answers in step forever.
 *
 * The admin API exposes templates only to a logged-in session; the REST API,
 * which is what a connector authenticates against, exposes published passports
 * but not the templates behind them. This closes that gap and nothing else: it
 * is read-only, and it reshapes rather than interprets — what a role or a tier
 * means is the connector's business, not this file's.
 */
class PassportTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        $templates = PassportTemplate::with(['fields.attribute', 'fields.section', 'families'])
            ->where('is_enabled', true)
            ->get();

        return response()->json([
            'data' => $templates->map(fn (PassportTemplate $template) => [
                'code'     => $template->code,
                // Which attribute families publish this passport. A connection
                // reads products of many families; only some of them carry this
                // template, and applying it to the rest would invent structure
                // nobody declared.
                'families' => $template->families->pluck('code')->values(),
                'fields'   => $template->fields->map(fn ($field) => [
                    'code'   => $field->code,
                    'label'  => $this->label($field),

                    // Where the value comes from. `attribute` names a product
                    // attribute; `fixed` is the same text for every product — a
                    // take-back scheme, a warranty statement — and carries no
                    // attribute at all.
                    'source_type' => $field->source_type,
                    'attribute'   => $field->attribute?->code,

                    // consumer | operator | authority
                    'tier' => $this->enumValue($field->tier),

                    // gtin | model | batch, or null for an ordinary row. This is
                    // the answer to "where does the GTIN live" straight from the
                    // person who decided it.
                    'role' => $this->enumValue($field->role),

                    'section'  => $field->section?->code,
                    'required' => (bool) $field->is_required,
                    'position' => $field->position,
                ])->values(),
            ])->values(),
        ]);
    }

    /**
     * The label in the admin's own locale.
     *
     * Translatable in UnoPim, and worth carrying: a field code is often an
     * abbreviation, and the label is the only clue to what a statement means
     * when the connector goes looking for a term for it.
     */
    private function label($field): string
    {
        $translation = $field->translations
            ?->firstWhere('locale', app()->getLocale())
            ?? $field->translations?->first();

        return (string) ($translation->label ?? $field->code);
    }

    /** Backed enums come back as enums; the wire wants their value. */
    private function enumValue($value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }
}

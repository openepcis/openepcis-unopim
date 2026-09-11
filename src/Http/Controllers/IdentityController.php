<?php

/*
 * Copyright (c) 2026 benelog GmbH & Co. KG
 * SPDX-License-Identifier: MIT
 */

namespace OpenEPCIS\UnoPim\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use OpenEPCIS\UnoPim\Bridge;

/**
 * What the admin screens call.
 *
 * Thin on purpose: each action is one call to the bridge and one shape back.
 * The judgement lives in {@see Bridge} — specifically the judgement about what
 * to say when the platform cannot be reached, which is the only part of this
 * that is not obvious.
 */
class IdentityController extends Controller
{
    public function __construct(private readonly Bridge $bridge)
    {
    }

    /**
     * Classification search behind the GPC field.
     *
     * A short query is answered with nothing rather than with everything: two
     * letters match half the classification, and a dropdown of half the
     * classification is a slower way of finding nothing.
     */
    public function gpc(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 3) {
            return response()->json(['ok' => true, 'results' => []]);
        }

        $answer = $this->bridge->searchGpc($query);

        return response()->json($answer, $answer['ok'] ? 200 : 502);
    }

    /**
     * Draws the next GTIN from the tenant's range.
     *
     * A refusal is passed through as it came: "the range is exhausted" and "the
     * platform is down" need different things done about them, and collapsing
     * them into "could not allocate" costs somebody an afternoon.
     */
    public function allocate(Request $request): JsonResponse
    {
        $answer = $this->bridge->allocate((string) $request->input('ai', '01'));

        return response()->json($answer, $answer['ok'] ? 200 : 502);
    }

    /** The verdict for one identifier, for the panel to render. */
    public function resolve(Request $request): JsonResponse
    {
        $value = trim((string) $request->query('value', ''));

        if ($value === '') {
            return response()->json(['ok' => true, 'results' => []]);
        }

        $answer = $this->bridge->resolve(
            (string) $request->query('ai', '01'),
            $value,
            $request->query('sku') ? (string) $request->query('sku') : null
        );

        return response()->json($answer, $answer['ok'] ? 200 : 502);
    }
}

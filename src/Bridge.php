<?php

/*
 * Copyright (c) 2026 benelog GmbH & Co. KG
 * SPDX-License-Identifier: MIT
 */

namespace OpenEPCIS\UnoPim;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The connector's bridge, as this side of it sees it.
 *
 * One class rather than calls scattered through controllers, because the
 * interesting part is not the HTTP: it is what happens when the bridge cannot
 * be reached. Every method here answers with a shape the caller can render, and
 * never with an exception that ends up as a blank screen.
 *
 * The distinction the whole package rests on: **the platform being unreachable
 * is not the merchant's data being wrong.** A classification search that
 * returns nothing because the bridge timed out must not look like "no such
 * category", or the next thing that happens is somebody typing an eight-digit
 * code by hand to get past it — and typing it wrong.
 */
class Bridge
{
    /** @return array{ok: bool, results?: array, error?: string} */
    public function searchGpc(string $query): array
    {
        return $this->get('/v1/gpc', [
            'tenantId' => $this->tenant(),
            'q'        => $query,
            'level'    => 'BRICK',
            'size'     => 20,
        ]);
    }

    /**
     * Draws the next identifier of a kind from the tenant's own range.
     *
     * Not a random number and not a guess: the resolver keeps the ledger, so a
     * number handed out here cannot be handed out again — which is the whole
     * reason this is a button and not a text field.
     *
     * @return array{ok: bool, results?: array, error?: string}
     */
    public function allocate(string $ai = '01'): array
    {
        // Ein eigenes, weites Zeitlimit — und der Grund liegt bei GS1: das
        // Ziehen liest deren Schluesselvorrat, und der wird beantwortet, indem
        // die gesamte Basisnummer sortiert wird. Gemessen ~12 Sekunden. Die
        // vier Sekunden, die fuer ein Urteil richtig sind (das kommt in 27 ms),
        // melden hier faelschlich "Plattform nicht erreichbar" fuer etwas, das
        // nur noch unterwegs war.
        //
        // Ein Formular-Post darf so lange warten: es laedt die Seite ohnehin
        // neu, und der Haendler hat auf eine Nummer geklickt, nicht auf eine
        // Anzeige.
        return $this->post('/v1/identifiers/allocate', [
            'tenantId' => $this->tenant(),
            'ai'       => $ai,
        ], (float) config('openepcis.allocate_timeout', 90));
    }

    /**
     * What the platform makes of an identifier: valid, whose prefix, known to
     * GS1 or not, and — the part that matters for a person — whether anything
     * is theirs to fix.
     *
     * @return array{ok: bool, results?: array, error?: string}
     */
    public function resolve(string $ai, string $value, ?string $sku = null): array
    {
        return $this->post('/v1/products/resolve', [
            'tenantId' => $this->tenant(),
            // The bridge logs and caches per product, so it needs to know which
            // one this is. The SKU is what UnoPim calls a product everywhere
            // else, so it is what appears in the connector's log too — the
            // point being that a merchant reading either one is reading the
            // same name.
            'sourceSystem'      => 'unopim',
            'externalProductId' => $sku ?? $value,
            'identifiers'       => [['ai' => $ai, 'value' => $value]],
        ]);
    }

    public function tenant(): string
    {
        return (string) config('openepcis.tenant');
    }

    private function get(string $path, array $query): array
    {
        return $this->call(fn () => Http::timeout($this->timeout())
            ->acceptJson()
            ->get($this->url($path), $query), $path);
    }

    private function post(string $path, array $body, ?float $timeout = null): array
    {
        return $this->call(fn () => Http::timeout($timeout ?? $this->timeout())
            ->acceptJson()
            ->post($this->url($path), $body), $path);
    }

    private function call(callable $request, string $path): array
    {
        try {
            $response = $request();
        } catch (\Throwable $e) {
            // Logged, because an operator needs to see it; summarised for the
            // screen, because a stack trace in a dropdown helps nobody.
            Log::warning('OpenEPCIS bridge unreachable', ['path' => $path, 'error' => $e->getMessage()]);

            return ['ok' => false, 'error' => trans('openepcis::app.bridge.unreachable')];
        }

        if ($response->successful()) {
            return ['ok' => true, 'results' => $response->json() ?? []];
        }

        // The bridge answers problems as RFC 9457, so it usually knows more
        // about what went wrong than a status code does. Preferred when there,
        // because "unknown tenant" is a configuration error somebody can fix
        // and "502" is not.
        $detail = $response->json('detail') ?? $response->json('title');
        Log::warning('OpenEPCIS bridge refused a request', [
            'path' => $path, 'status' => $response->status(), 'detail' => $detail,
        ]);

        return [
            'ok'    => false,
            'error' => $detail ?: trans('openepcis::app.bridge.refused', ['status' => $response->status()]),
        ];
    }

    private function url(string $path): string
    {
        return rtrim((string) config('openepcis.bridge_url'), '/').$path;
    }

    private function timeout(): float
    {
        return (float) config('openepcis.timeout', 4);
    }
}

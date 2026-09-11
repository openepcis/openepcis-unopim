<?php

/*
 * Copyright (c) 2026 benelog GmbH & Co. KG
 * SPDX-License-Identifier: MIT
 */

namespace OpenEPCIS\UnoPim;

use Illuminate\Support\Facades\Cache;
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
    private const TOKEN_CACHE_KEY = 'openepcis:bridge-token';

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
        return $this->call(fn () => $this->client()->get($this->url($path), $query), $path);
    }

    private function post(string $path, array $body, ?float $timeout = null): array
    {
        return $this->call(fn () => $this->client($timeout)->post($this->url($path), $body), $path);
    }

    /**
     * A request already carrying the bearer the bridge requires.
     *
     * The bridge is @Authenticated and reads a defaultGroup claim off the token
     * to decide which connection the caller may drive. Without a token every
     * call is a 401 -- exactly what the panel shows as "the OpenEPCIS platform
     * refused the request (401)" when this is not configured.
     *
     * When no client is configured the request goes out unauthenticated, so a
     * genuinely open bridge (a local dev run) still works and a misconfigured
     * one fails with the same 401 as before rather than silently.
     */
    private function client(?float $timeout = null): \Illuminate\Http\Client\PendingRequest
    {
        $request = Http::timeout($timeout ?? $this->timeout())->acceptJson();
        $token = $this->token();

        return $token === null ? $request : $request->withToken($token);
    }

    /**
     * A client-credentials access token for the bridge, cached until shortly
     * before it expires.
     *
     * client_credentials, not a stored offline token: this side has no user and
     * needs none. The identity is the connection's own service client, and the
     * defaultGroup claim that scopes it to this tenant is stamped on the token
     * by Keycloak, not chosen here.
     *
     * A failure to mint returns null rather than throwing: the caller renders
     * "platform unreachable", the honest thing to show when the token endpoint
     * is down, and never a stack trace in a dropdown.
     */
    private function token(): ?string
    {
        $clientId = (string) config('openepcis.auth.client_id');
        $secret = (string) config('openepcis.auth.client_secret');
        $tokenUrl = (string) config('openepcis.auth.token_url');

        if ($clientId === '' || $secret === '' || $tokenUrl === '') {
            return null;
        }

        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::asForm()->timeout($this->timeout())->post($tokenUrl, [
                'grant_type'    => 'client_credentials',
                'client_id'     => $clientId,
                'client_secret' => $secret,
            ]);
        } catch (\Throwable $e) {
            Log::warning('OpenEPCIS bridge token endpoint unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('OpenEPCIS bridge token request refused', ['status' => $response->status()]);

            return null;
        }

        $token = (string) $response->json('access_token');
        if ($token === '') {
            return null;
        }

        // A little short of expiry, floored at 30s, so a call never rides a
        // token that expires in flight. Re-minting is one cheap round trip.
        $ttl = max(30, (int) $response->json('expires_in', 60) - 30);
        Cache::put(self::TOKEN_CACHE_KEY, $token, $ttl);

        return $token;
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

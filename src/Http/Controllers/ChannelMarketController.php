<?php

/*
 * Copyright (c) 2026 benelog GmbH & Co. KG
 * SPDX-License-Identifier: MIT
 */

namespace OpenEPCIS\UnoPim\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Webkul\Core\Repositories\ChannelRepository;

/**
 * What market each channel sells into, as GS1 states it.
 *
 * The connector needs this per channel and cannot read UnoPim's core
 * configuration through the stock REST API — `core_config` is not exposed
 * there. So the one thing it needs is exposed here, on the same API and behind
 * the same token, rather than by teaching the connector to read a database.
 *
 * The shape is deliberately the GS1 term's, not UnoPim's: countries as ISO
 * 3166-1 alpha-2 and an optional first-availability date, which together are a
 * `gs1:TargetMarketDetails`. Translating at the edge means the connector maps
 * a term rather than guessing what a channel meant.
 */
class ChannelMarketController extends Controller
{
    private const COUNTRIES = 'catalog.gs1.settings.target_market_countries';

    private const FIRST_AVAILABILITY = 'catalog.gs1.settings.first_availability';

    public function __construct(private readonly ChannelRepository $channels) {}

    public function index(): JsonResponse
    {
        $data = $this->channels->all()->map(function ($channel): array {
            $countries = $this->countries($channel->code);

            return [
                'channel'   => $channel->code,
                'locales'   => $channel->locales->pluck('code')->values(),
                // A list of TargetMarketDetails, one per country.
                //
                // The ontology says targetMarketCountries relates to a Country;
                // the resolver's DTO says exactly one per details entry. So a
                // channel serving several countries is several entries, not one
                // entry holding a list — which is the shape the catalog accepts
                // and therefore the shape to hand over.
                //
                // Absent rather than empty when nothing is configured: a channel
                // with no target market has not been told one, which is a
                // different thing from being told it sells nowhere.
                'targetMarket' => $countries === []
                    ? null
                    : array_map(
                        fn (string $code): array => array_filter([
                            'targetMarketCountries' => ['countryCode' => $code],
                            'consumerFirstAvailabilityDateTime' => $this->firstAvailability($channel->code),
                        ]),
                        $countries
                    ),
            ];
        })->values();

        return new JsonResponse(['data' => $data]);
    }

    /** @return array<int,string> */
    private function countries(string $channel): array
    {
        $raw = (string) (core()->getConfigData(self::COUNTRIES, $channel) ?? '');

        return array_values(array_filter(array_map(
            static fn (string $code): string => strtoupper(trim($code)),
            explode(',', $raw)
        )));
    }

    /**
     * The first-availability date as the catalog takes it.
     *
     * <p>The setting is a date because that is what a merchant knows; the DTO
     * field is an OffsetDateTime. Widening it here rather than asking for a
     * timestamp keeps the question answerable — and midnight UTC is what a date
     * means when a date is all anybody has.
     */
    private function firstAvailability(string $channel): ?string
    {
        $value = core()->getConfigData(self::FIRST_AVAILABILITY, $channel);

        if ($value === null || $value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC')))
                ->format('Y-m-d\\TH:i:s\\Z');
        } catch (\Exception) {
            return null;
        }
    }
}

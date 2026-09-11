{{--
    Copyright (c) 2026 benelog GmbH & Co. KG
    SPDX-License-Identifier: MIT

    Shown above the product form, not on a screen of its own. Identity is not a
    separate task somebody remembers to do; it is a property of the product they
    are already editing, and a second screen is one nobody opens.

    Almost nothing here is stored. One attribute comes back from the connector —
    whether there is something to fix — and the rest is asked of the bridge when
    somebody looks. A licensee name written into a product last month is a claim
    about last month; this way the panel is either current or honestly silent.
--}}
@php
    $codes = config('openepcis.attributes');

    $gtin = $codes['gtin'] === 'sku'
        ? data_get($product, 'sku')
        : data_get($product, 'values.common.'.$codes['gtin']);

    $batch = data_get($product, 'values.common.'.$codes['batch']);

    // "Needs attention" is the connector's own word for it, and the only one a
    // merchant should act on.
    $needsAttention = (data_get($product, 'values.common.'.$codes['action_required']) ?? 'no') === 'yes';

    // Das Urteil wird HIER geholt, nicht im Browser.
    //
    // Es hing zuvor an einem fetch beim DOMContentLoaded — und blieb auf
    // "Asking the platform…" stehen, obwohl die Brücke in 27 ms antwortet:
    // das Produktformular ist eine Vue-Anwendung, die den eingehängten
    // Bereich nach dem Laden neu zeichnet und die Änderung damit verwirft.
    // Serverseitig gerendert gibt es kein Zeitfenster, in dem das passieren
    // kann, und das Panel funktioniert auch ohne JavaScript.
    //
    // Die Brücke hat ein eigenes kurzes Zeitlimit und liefert bei Störung ein
    // 'ok' => false zurück; ein Formular wartet also nie lange und nie ewig.
    $verdict = null;
    $verdictError = null;
    $carrier = null;

    if ($gtin) {
        $answer = resolve(\OpenEPCIS\UnoPim\Bridge::class)->resolve('01', $gtin, data_get($product, 'sku'));

        if ($answer['ok'] ?? false) {
            $verdict = $answer['results']['verdicts'][0] ?? null;
        } else {
            $verdictError = $answer['error'] ?? null;
        }

        // Der Träger zum Scannen, direkt hier gerendert.
        //
        // GROSSGESCHRIEBEN im QR, und das ist keine Kosmetik: der
        // alphanumerische QR-Modus kennt nur Ziffern, Grossbuchstaben und eine
        // Handvoll Zeichen, kodiert dafür aber rund 45 % dichter als der
        // Byte-Modus. Ein GS1 Digital Link passt hinein, sobald er keine
        // Kleinbuchstaben mehr enthält — dieselbe Adresse, weniger Module,
        // besser lesbar aus der Entfernung. Schema und Host sind ohnehin
        // unabhängig von der Schreibweise; der Pfad einer GS1-Digital-Link-URI
        // ist es per Standard ebenfalls.
        //
        // BaconQrCode bringt UnoPim selbst mit (sein eigener Carrier nutzt ihn),
        // also kommt keine Abhängigkeit dazu.
        if ($verdict && ($verdict['digitalLink'] ?? null)) {
            try {
                $writer = new \BaconQrCode\Writer(new \BaconQrCode\Renderer\ImageRenderer(
                    new \BaconQrCode\Renderer\RendererStyle\RendererStyle(160, 1),
                    new \BaconQrCode\Renderer\Image\SvgImageBackEnd
                ));
                $carrier = $writer->writeString(strtoupper($verdict['digitalLink']));
            } catch (\Throwable $e) {
                // Ein fehlender Träger ist kein Grund, das Formular zu
                // verweigern — die Nummer steht ja daneben.
                $carrier = null;
            }
        }
    }
@endphp

<div class="mb-4 rounded border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900"
     id="openepcis-panel"
     data-url="{{ route('openepcis.resolve') }}"
     data-sku="{{ data_get($product, 'sku') }}"
     data-gtin="{{ $gtin }}">

    <div class="mb-3 flex items-center justify-between">
        <p class="text-base font-semibold text-gray-800 dark:text-white">
            {{ trans('openepcis::app.panel.title') }}
        </p>

        @if ($needsAttention)
            <span class="rounded bg-red-100 px-2 py-1 text-xs font-semibold text-red-800">
                {{ trans('openepcis::app.panel.action') }}
            </span>
        @endif
    </div>

    @if (! $gtin)
        {{-- Zwei gleichrangige Wege, weil ein Händler in einer von zwei Lagen
             an ein Produktformular kommt: er bringt einen Artikel mit, der
             schon eine Nummer hat, oder er legt einen an, der keine hat. Nur
             das Ziehen anzubieten sagt dem ersten, seine Nummer sei
             unerwünscht; nur das Feld lässt den zweiten eine erfinden.

             Beides sind Formulare und kein JavaScript. Das Panel wird über
             UnoPims view_render_event nachträglich eingehängt, und ein so
             eingefügtes <script> führt der Browser nie aus — die Knöpfe taten
             wortlos nichts. Ein Formular hat das Problem nicht und kommt ohne
             JavaScript aus. --}}
        <p class="mb-3 text-sm text-gray-600 dark:text-gray-300">
            {{ trans('openepcis::app.panel.no-gtin') }}
        </p>

        @if (session('error'))
            <p class="mb-3 text-sm" style="color:#dc2626">{{ session('error') }}</p>
        @endif
        @if (session('success'))
            <p class="mb-3 text-sm" style="color:#16a34a">{{ session('success') }}</p>
        @endif

        <div class="flex flex-wrap items-start gap-4">
            <form method="POST" action="{{ route('openepcis.deposit', ['id' => $product->id]) }}"
                  class="flex flex-wrap items-center gap-2">
                @csrf
                <input type="hidden" name="sku" value="{{ data_get($product, 'sku') }}">
                <input type="text" name="gtin" inputmode="numeric" autocomplete="off"
                       placeholder="{{ trans('openepcis::app.panel.have-placeholder') }}"
                       style="font-family:ui-monospace,monospace"
                       class="rounded border border-gray-300 px-2 py-1 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                <button type="submit" class="secondary-button">
                    {{ trans('openepcis::app.panel.have') }}
                </button>
            </form>

            <form method="POST" action="{{ route('openepcis.draw', ['id' => $product->id]) }}">
                @csrf
                <button type="submit" class="secondary-button">
                    {{ trans('openepcis::app.panel.draw') }}
                </button>
            </form>
        </div>

    @else
        <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
            <dt class="text-gray-500">{{ trans('openepcis::app.panel.gtin') }}</dt>
            <dd class="font-mono text-gray-900 dark:text-white">{{ $gtin }}</dd>

            @if ($batch)
                {{-- A product record that names a batch is a record about one
                     batch. Shown because it changes what the passport is: the
                     catalog holds it at the instance path, under the model. --}}
                <dt class="text-gray-500">{{ trans('openepcis::app.panel.batch') }}</dt>
                <dd class="font-mono text-gray-900 dark:text-white">{{ $batch }}</dd>
            @endif

            <dt class="text-gray-500">{{ trans('openepcis::app.panel.status') }}</dt>
            <dd class="text-gray-900 dark:text-white">
                @if ($verdict === null)
                    <span class="text-gray-400">—</span>
                @elseif ($verdict['dataProblem'] ?? false)
                    <span class="text-red-700">{{ $verdict['reason'] ?? 'invalid' }}</span>
                @elseif ($verdict['offlineValid'] ?? false)
                    <span class="text-green-700">{{ trans('openepcis::app.panel.valid') }}</span>
                @else
                    <span class="text-gray-400">—</span>
                @endif
            </dd>

            @if ($verdict && ($verdict['licenseeName'] ?? null))
                <dt class="text-gray-500">{{ trans('openepcis::app.panel.licensee') }}</dt>
                <dd class="text-gray-900 dark:text-white">{{ $verdict['licenseeName'] }}</dd>
            @endif

            <dt class="text-gray-500">{{ trans('openepcis::app.panel.prefix') }}</dt>
            <dd class="text-gray-900 dark:text-white">
                @if ($verdict && ($verdict['gcpLength'] ?? null))
                    <span class="font-mono">{{ substr($gtin, 1, $verdict['gcpLength']) }}</span>
                    @if (($verdict['gcpOwnership'] ?? '') === 'own_prefix')
                        <span class="text-xs text-gray-500">({{ trans('openepcis::app.panel.own-prefix') }})</span>
                    @endif
                @else
                    —
                @endif
            </dd>

            <dt class="text-gray-500">{{ trans('openepcis::app.panel.digital-link') }}</dt>
            <dd>
                @if ($verdict && ($verdict['digitalLink'] ?? null))
                    {{-- Inline-Stil, keine Tailwind-Klasse: UnoPims CSS ist fertig
                         kompiliert, und eine Klasse, die dort nicht vorkommt,
                         existiert nicht — `text-blue-700` blieb wirkungslos und
                         der Link erbte die Textfarbe, im Dunkelmodus also
                         schwarz auf dunkel. Der gewählte Ton trägt auf hellem
                         wie auf dunklem Grund. --}}
                    <a href="{{ $verdict['digitalLink'] }}" target="_blank" rel="noopener"
                       style="color:#3b82f6;text-decoration:underline;font-family:ui-monospace,monospace;font-size:0.75rem;word-break:break-all">{{ $verdict['digitalLink'] }}</a>
                @else
                    —
                @endif
            </dd>
        </dl>

        @if ($carrier)
            <div class="mt-3 flex items-start gap-3">
                {{-- Weisser Grund fest gesetzt: ein QR braucht den Kontrast, und
                     im Dunkelmodus stuende er sonst dunkel auf dunkel. --}}
                <div style="background:#fff;padding:6px;border-radius:4px;line-height:0">
                    {!! $carrier !!}
                </div>
            </div>
        @endif

        @if (session('error'))
            <p class="mt-2 text-sm" style="color:#dc2626">{{ session('error') }}</p>
        @endif
        @if (session('success'))
            <p class="mt-2 text-sm" style="color:#16a34a">{{ session('success') }}</p>
        @endif

        @if ($verdictError)
            {{-- Die Plattform ist nicht erreichbar. Das sind nicht die Daten des
                 Händlers, und das Panel darf beides nicht gleich aussehen lassen. --}}
            <p class="mt-2 text-xs text-red-700">{{ $verdictError }}</p>
        @elseif ($verdict && ($verdict['registryStatus'] ?? '') !== 'verified')
            <p class="mt-2 text-xs text-gray-500">{{ trans('openepcis::app.panel.unverified') }}</p>
        @endif
    @endif
</div>

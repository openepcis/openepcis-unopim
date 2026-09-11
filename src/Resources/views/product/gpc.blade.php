{{--
    Copyright (c) 2026 benelog GmbH & Co. KG
    SPDX-License-Identifier: MIT

    A GPC brick is eight digits and nobody knows them. A plain text field for
    one gets a wrong code or an empty one — and a wrong classification is the
    worse of the two, because it looks filled in.
--}}
@php
    $codes   = config('openepcis.attributes');
    $current = data_get($product, 'values.common.'.$codes['gpc']);
    // The code is what is stored; a person recognises the words. Until they
    // search, the code is all there is to show — better than an empty box that
    // suggests nothing was ever chosen.
    $currentLabel = $current ?: '';
@endphp

<div class="mb-4 rounded border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900"
     id="openepcis-gpc"
     data-url="{{ route('openepcis.gpc') }}"
     data-searching="{{ trans('openepcis::app.gpc.searching') }}"
     data-none="{{ trans('openepcis::app.gpc.none') }}"
     data-chosen="{{ trans('openepcis::app.gpc.chosen') }}">

    <label class="mb-1 block text-sm font-semibold text-gray-800 dark:text-white">
        {{ trans('openepcis::app.gpc.label') }}
    </label>

    {{-- This widget IS the field, not a helper beside one. The attribute is
         taken out of the form so there is no second, raw box inviting somebody
         to type the eight digits after all — which is the mistake the search
         exists to prevent. The value rides in a hidden input under the name the
         form already posts. --}}
    <input type="hidden"
           id="openepcis-gpc-value"
           name="values[common][{{ $codes['gpc'] }}]"
           value="{{ $current }}">

    <input type="search"
           id="openepcis-gpc-search"
           autocomplete="off"
           value="{{ $currentLabel }}"
           placeholder="{{ trans('openepcis::app.gpc.placeholder') }}"
           class="w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white">

    <ul id="openepcis-gpc-results"
        class="mt-1 max-h-56 overflow-y-auto rounded border border-gray-200 text-sm empty:hidden dark:border-gray-800"></ul>

    <p id="openepcis-gpc-note" class="mt-1 text-xs text-gray-500">
        @if ($current)
            {{ trans('openepcis::app.gpc.stored', ['code' => $current]) }}
        @else
            {{ trans('openepcis::app.gpc.hint') }}
        @endif
    </p>
</div>

@pushOnce('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const root = document.getElementById('openepcis-gpc');

            if (! root) {
                return;
            }

            const input   = document.getElementById('openepcis-gpc-search');
            const list    = document.getElementById('openepcis-gpc-results');
            const note    = document.getElementById('openepcis-gpc-note');
            const stored  = document.getElementById('openepcis-gpc-value');
            let   pending = null;

            // Debounced, because this fires on every keystroke and the person
            // typing is still typing. Also aborted: an answer to "cof" arriving
            // after the answer to "coffee" would replace the better list with
            // the worse one.
            let timer = null;

            input.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(search, 250);
            });

            async function search() {
                const query = input.value.trim();

                if (query.length < 3) {
                    list.replaceChildren();
                    return;
                }

                pending?.abort();
                pending = new AbortController();
                note.textContent = root.dataset.searching;

                let answer;

                try {
                    const response = await fetch(root.dataset.url + '?q=' + encodeURIComponent(query), {
                        headers: { 'Accept': 'application/json' },
                        signal: pending.signal,
                    });
                    answer = await response.json();
                } catch (error) {
                    if (error.name === 'AbortError') {
                        return;
                    }
                    // Deliberately not "nothing matched": a search that failed
                    // and a search that found nothing call for different things,
                    // and confusing them teaches people to type the code by hand.
                    note.textContent = answer?.error ?? '⚠ ' + error.message;
                    note.className = 'mt-1 text-xs text-red-700';
                    return;
                }

                if (! answer.ok) {
                    note.textContent = answer.error;
                    note.className = 'mt-1 text-xs text-red-700';
                    return;
                }

                note.className = 'mt-1 text-xs text-gray-500';
                render(answer.results ?? []);
            }

            function render(results) {
                list.replaceChildren();

                if (! results.length) {
                    note.textContent = root.dataset.none;
                    return;
                }

                note.textContent = '';

                for (const entry of results) {
                    const code  = entry.code ?? entry.brickCode ?? '';
                    const title = entry.title ?? entry.brickDescription ?? entry.description ?? code;
                    const item  = document.createElement('li');

                    item.className = 'cursor-pointer px-3 py-2 hover:bg-gray-100 dark:hover:bg-gray-800';
                    item.textContent = title + '  ·  ' + code;
                    item.addEventListener('click', function () {
                        if (stored) {
                            stored.value = code;
                        }
                        input.value = title;
                        list.replaceChildren();
                        note.textContent = root.dataset.chosen
                            .replace(':title', title)
                            .replace(':code', code);
                    });

                    list.appendChild(item);
                }
            }
        });
    </script>
@endPushOnce

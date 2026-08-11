@php
    /** @var App\Models\DealOffer $offer */
    use App\Filament\Resources\DealOfferResource;
    use App\Models\DealOffer;

    $search = $offer->dealSearch;
    $type = $search?->component_type;
    $source = match ($offer->source) {
        'best_buy_api' => 'API oficial de Best Buy',
        'dealnews_rss' => 'Oferta publicada por DealNews',
        'direct_extract' => 'Página verificada automáticamente',
        'browser_capture' => 'Verificado en tu navegador',
        'browser_discovery' => 'Aprendido por el Radar local',
        DealOffer::USER_CONFIRMED_SOURCE => 'Confirmado manualmente por ti',
        default => 'Descubrimiento pendiente de verificar',
    };
    $hasVerifiedPrice = $offer->hasVerifiedPrice();
    $canVerifyInBrowser = $offer->supportsBrowserCapture();
    $underTarget = $hasVerifiedPrice
        && ! $offer->isOutOfStock()
        && $search?->target_price !== null
        && (float) $offer->price <= (float) $search->target_price;
    $history = $offer->priceSnapshots->sortBy('captured_at')->values();
    $currentPrice = $hasVerifiedPrice ? (float) $offer->price : null;
    $previousPrice = $currentPrice === null ? null : $history
        ->reverse()
        ->first(fn ($snapshot) => abs((float) $snapshot->price - $currentPrice) > 0.005)?->price;
    $drop = $previousPrice !== null && (float) $previousPrice > $currentPrice
        ? (float) $previousPrice - $currentPrice
        : null;
    $dropPercent = $drop !== null ? ($drop / (float) $previousPrice) * 100 : null;
    $historyUrl = DealOfferResource::getUrl('view', ['record' => $offer]);
@endphp

<article class="pc-ui-card pc-deal-offer-card group overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-950/10 transition duration-200 hover:shadow-lg hover:ring-primary-500/40 dark:bg-gray-900 dark:ring-white/10">
    <div class="grid min-w-0 grid-cols-1 md:grid-cols-[11rem_minmax(0,1fr)] xl:grid-cols-[12rem_minmax(0,1fr)_16rem]">
        <a
            href="{{ $historyUrl }}"
            class="relative block min-h-44 overflow-hidden bg-gray-950 md:min-h-48"
            aria-label="Ver historial de {{ $offer->title }}"
        >
            <x-pc-part-visual
                :src="$offer->image_url"
                :alt="$offer->title"
                :type="$type"
                class="h-full min-h-44 w-full rounded-none border-0 ring-0 transition duration-300 group-hover:scale-[1.02] md:min-h-48"
            />

            <div class="absolute left-3 top-3">
                <x-filament::badge :color="$type?->getColor() ?? 'gray'">
                    {{ $type?->getLabel() ?? 'Componente' }}
                </x-filament::badge>
            </div>
        </a>

        <div class="flex min-w-0 flex-col gap-3 p-4 sm:p-5">
            <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                <span class="inline-flex min-w-0 items-center gap-1.5 font-bold uppercase tracking-wide text-gray-700 dark:text-gray-200">
                    <span class="h-2 w-2 shrink-0 rounded-full {{ $offer->isOutOfStock() ? 'bg-danger-500' : ($hasVerifiedPrice ? 'bg-success-500' : 'bg-warning-500') }}"></span>
                    <span class="truncate">{{ $offer->store }}</span>
                </span>
                <span aria-hidden="true">·</span>
                <span>{{ $type?->getLabel() ?? 'Componente' }}</span>
                @if ($offer->fetched_at)
                    <span aria-hidden="true">·</span>
                    <span title="{{ $offer->fetched_at->toDateTimeString() }}">{{ $offer->fetched_at->diffForHumans() }}</span>
                @endif
            </div>

            <h3 class="text-base font-bold leading-6 text-gray-950 dark:text-white">
                <a href="{{ $historyUrl }}" class="line-clamp-2 break-words hover:text-primary-600 dark:hover:text-primary-400" title="{{ $offer->title }}">
                    {{ $offer->title }}
                </a>
            </h3>

            <p class="truncate text-xs text-gray-500 dark:text-gray-400" title="{{ $search?->name }}">
                Cacería: {{ $search?->name }}
            </p>

            <div class="mt-auto flex min-w-0 flex-wrap items-center gap-2 text-xs">
                <span class="inline-flex min-w-0 items-center gap-1.5 rounded-lg bg-gray-50 px-2.5 py-1.5 text-gray-600 dark:bg-white/5 dark:text-gray-300">
                    <x-filament::icon icon="heroicon-m-signal" class="h-4 w-4 shrink-0" />
                    <span class="truncate" title="{{ $source }}">{{ $source }}</span>
                </span>
                @if ($offer->seller)
                    <span class="max-w-48 truncate rounded-lg bg-gray-50 px-2.5 py-1.5 font-medium text-gray-700 dark:bg-white/5 dark:text-gray-200" title="Vendido por {{ $offer->seller }}">
                        {{ $offer->seller }}
                    </span>
                @endif
            </div>

            <div class="flex flex-wrap gap-2">
                @if ($underTarget)
                    <x-filament::badge color="success" icon="heroicon-m-arrow-trending-down">Bajo objetivo</x-filament::badge>
                @elseif (! $hasVerifiedPrice)
                    <x-filament::badge color="warning" icon="heroicon-m-exclamation-triangle">Falta verificar</x-filament::badge>
                @endif

                @if ($offer->isOutOfStock())
                    <x-filament::badge color="danger" icon="heroicon-m-x-circle">Agotado</x-filament::badge>
                @elseif ($offer->availability === DealOffer::AVAILABILITY_IN_STOCK)
                    <x-filament::badge color="success" icon="heroicon-m-check-circle">Disponible</x-filament::badge>
                @endif
            </div>
        </div>

        <aside
            class="flex min-w-0 flex-col border-t border-gray-200 bg-gray-50/70 p-4 dark:border-white/10 dark:bg-white/[0.025] md:col-span-2 xl:col-span-1 xl:border-s xl:border-t-0"
            x-data="{ showPriceConfirmation: false, confirmedPrice: '' }"
        >
            @if ($hasVerifiedPrice)
                <div class="flex flex-wrap items-end gap-2">
                    <p class="text-3xl font-black tracking-tight {{ $offer->isOutOfStock() ? 'text-gray-500 line-through' : 'text-success-600 dark:text-success-400' }}">
                        ${{ number_format((float) $offer->price, 2) }}
                    </p>
                    @if ($drop !== null)
                        <span class="mb-1 rounded-lg bg-success-50 px-2 py-1 text-xs font-bold text-success-700 dark:bg-success-950/50 dark:text-success-300">
                            ↓ {{ number_format($dropPercent, 1) }}%
                        </span>
                    @endif
                </div>

                @if ($offer->isOutOfStock())
                    <p class="mt-1 text-xs font-semibold text-danger-600 dark:text-danger-400">Último precio conocido; actualmente agotado.</p>
                @elseif ($underTarget)
                    <p class="mt-1 text-xs font-semibold text-success-600 dark:text-success-400">
                        ${{ number_format((float) $search->target_price - (float) $offer->price, 2) }} bajo tu objetivo
                    </p>
                @elseif ($drop !== null)
                    <p class="mt-1 text-xs font-semibold text-success-600 dark:text-success-400">Bajó ${{ number_format($drop, 2) }}</p>
                @elseif ($search?->target_price !== null)
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Objetivo: ${{ number_format((float) $search->target_price, 2) }}</p>
                @endif
            @else
                <p class="text-base font-bold text-gray-800 dark:text-gray-100">Precio sin verificar</p>
                <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">Abre la tienda para capturar el precio visible en USD.</p>
            @endif

            <div class="mt-4 flex flex-wrap gap-2">
                <x-filament::button
                    tag="a"
                    :href="$offer->url"
                    target="_blank"
                    rel="noopener noreferrer"
                    color="gray"
                    size="sm"
                    icon="heroicon-m-arrow-top-right-on-square"
                >
                    Ver producto
                </x-filament::button>

                @if ($canVerifyInBrowser)
                    <x-filament::button
                        tag="a"
                        :href="$offer->browserCaptureLaunchUrl()"
                        target="_blank"
                        rel="noopener noreferrer"
                        size="sm"
                        icon="heroicon-m-check-badge"
                    >
                        {{ $offer->isOutOfStock() ? 'Revisar stock' : 'Revisar precio' }}
                    </x-filament::button>
                @endif

                @if ($hasVerifiedPrice || $offer->amazonAsin())
                    <x-filament::button
                        tag="a"
                        :href="$historyUrl"
                        color="gray"
                        size="sm"
                        icon="heroicon-m-chart-bar-square"
                    >
                        Historial
                    </x-filament::button>
                @endif
            </div>

            @if (! $hasVerifiedPrice)
                <div class="mt-3">
                    <button
                        type="button"
                        class="text-xs font-semibold text-primary-600 hover:underline dark:text-primary-400"
                        x-on:click="showPriceConfirmation = ! showPriceConfirmation"
                    >
                        Confirmar manualmente
                    </button>
                </div>

                <div x-cloak x-show="showPriceConfirmation" x-transition class="mt-3 rounded-xl bg-white p-3 ring-1 ring-gray-950/5 dark:bg-gray-950 dark:ring-white/10">
                    <label class="text-xs font-medium text-gray-700 dark:text-gray-200">Precio actual en USD</label>
                    <div class="mt-2 flex gap-2">
                        <div class="flex min-w-0 flex-1 items-center rounded-lg bg-white ring-1 ring-gray-950/10 dark:bg-gray-950 dark:ring-white/10">
                            <span class="ps-3 text-sm text-gray-500">$</span>
                            <input
                                type="number"
                                min="0.01"
                                max="10000"
                                step="0.01"
                                inputmode="decimal"
                                placeholder="329.00"
                                x-model="confirmedPrice"
                                class="min-w-0 flex-1 border-0 bg-transparent px-2 py-2 text-sm text-gray-950 outline-none focus:ring-0 dark:text-white"
                                x-on:keydown.enter.prevent="$refs.saveConfirmedPrice.click()"
                            >
                        </div>
                        <x-filament::button
                            type="button"
                            size="sm"
                            x-ref="saveConfirmedPrice"
                            x-bind:disabled="! confirmedPrice"
                            wire:loading.attr="disabled"
                            wire:target="confirmOfferPrice"
                            x-on:click="$wire.confirmOfferPrice({{ $offer->getKey() }}, confirmedPrice).then(() => { showPriceConfirmation = false; confirmedPrice = '' })"
                        >
                            Guardar
                        </x-filament::button>
                    </div>
                </div>
            @endif

            <p class="mt-auto pt-4 text-[11px] leading-4 text-gray-400">Confirma stock, vendedor, impuestos y garantía antes de comprar.</p>
        </aside>
    </div>
</article>

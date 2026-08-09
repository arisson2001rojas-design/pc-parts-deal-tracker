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

<article class="pc-deal-card group flex h-full min-w-0 flex-col overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-950/10 transition duration-200 hover:-translate-y-1 hover:shadow-xl hover:ring-primary-500/40 dark:bg-gray-900 dark:ring-white/10">
    <div class="relative overflow-hidden bg-gray-950">
        <x-pc-part-visual
            :src="$offer->image_url"
            :alt="$offer->title"
            :type="$type"
            class="aspect-[16/9] w-full rounded-none border-0 ring-0 transition duration-300 group-hover:scale-[1.02]"
        />

        <div class="absolute inset-x-3 top-3 flex items-start justify-between gap-2">
            <div class="flex flex-wrap gap-2">
                <x-filament::badge :color="$type?->getColor() ?? 'gray'">
                    {{ $type?->getLabel() ?? 'Componente' }}
                </x-filament::badge>
                @if ($underTarget)
                    <x-filament::badge color="success" icon="heroicon-m-arrow-trending-down">
                        Bajo objetivo
                    </x-filament::badge>
                @elseif (! $hasVerifiedPrice)
                    <x-filament::badge color="warning" icon="heroicon-m-exclamation-triangle">
                        Falta verificar
                    </x-filament::badge>
                @endif
            </div>

            @if ($offer->isOutOfStock())
                <x-filament::badge color="danger" icon="heroicon-m-x-circle">Agotado</x-filament::badge>
            @elseif ($offer->availability === DealOffer::AVAILABILITY_IN_STOCK)
                <x-filament::badge color="success" icon="heroicon-m-check-circle">Disponible</x-filament::badge>
            @endif
        </div>
    </div>

    <div class="flex min-w-0 flex-1 flex-col p-5">
        <div class="mb-3 flex items-center justify-between gap-3 text-xs text-gray-500 dark:text-gray-400">
            <span class="inline-flex items-center gap-1.5 font-bold uppercase tracking-wide text-gray-700 dark:text-gray-200">
                <span class="h-2 w-2 rounded-full {{ $offer->isOutOfStock() ? 'bg-danger-500' : ($hasVerifiedPrice ? 'bg-success-500' : 'bg-warning-500') }}"></span>
                {{ $offer->store }}
            </span>
            <span class="shrink-0">{{ $offer->fetched_at?->diffForHumans() }}</span>
        </div>

        <h3 class="line-clamp-3 break-words text-base font-bold leading-6 text-gray-950 dark:text-white">
            {{ $offer->title }}
        </h3>
        <p class="mt-1 truncate text-xs text-gray-500 dark:text-gray-400" title="{{ $search?->name }}">
            Cacería: {{ $search?->name }}
        </p>

        <div class="mt-auto pt-5">
            @if ($hasVerifiedPrice)
                <div class="flex flex-wrap items-end justify-between gap-2">
                    <p class="text-3xl font-black tracking-tight {{ $offer->isOutOfStock() ? 'text-gray-500 line-through' : 'text-success-600 dark:text-success-400' }}">
                        ${{ number_format((float) $offer->price, 2) }}
                    </p>
                    @if ($drop !== null)
                        <span class="rounded-lg bg-success-50 px-2 py-1 text-xs font-bold text-success-700 dark:bg-success-950/50 dark:text-success-300">
                            ↓ {{ number_format($dropPercent, 1) }}%
                        </span>
                    @endif
                </div>

                @if ($offer->isOutOfStock())
                    <p class="mt-1 text-xs font-semibold text-danger-600 dark:text-danger-400">Precio registrado, pero ahora figura agotado.</p>
                @elseif ($underTarget)
                    <p class="mt-1 text-xs font-semibold text-success-600 dark:text-success-400">
                        ${{ number_format((float) $search->target_price - (float) $offer->price, 2) }} por debajo de tu objetivo
                    </p>
                @elseif ($drop !== null)
                    <p class="mt-1 text-xs font-semibold text-success-600 dark:text-success-400">
                        Bajó ${{ number_format($drop, 2) }} desde el precio anterior
                    </p>
                @elseif ($search?->target_price !== null)
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Objetivo: ${{ number_format((float) $search->target_price, 2) }}</p>
                @endif
            @else
                <p class="text-lg font-bold text-gray-700 dark:text-gray-200">Precio sin verificar</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Abre la tienda para capturar el precio visible en USD.</p>
            @endif

            <div class="mt-4 rounded-xl bg-gray-50 px-3 py-2.5 text-xs dark:bg-white/5">
                <div class="flex items-center justify-between gap-3">
                    <span class="truncate text-gray-500 dark:text-gray-400">{{ $source }}</span>
                    @if ($offer->seller)
                        <span class="max-w-32 truncate font-medium text-gray-700 dark:text-gray-200" title="{{ $offer->seller }}">{{ $offer->seller }}</span>
                    @endif
                </div>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-2">
                @if ($hasVerifiedPrice || $offer->amazonAsin())
                    <x-filament::button
                        tag="a"
                        :href="$historyUrl"
                        color="gray"
                        size="sm"
                        icon="heroicon-m-chart-bar-square"
                        class="justify-center"
                    >
                        Historial
                    </x-filament::button>
                @else
                    <span></span>
                @endif

                <x-filament::button
                    tag="a"
                    :href="$canVerifyInBrowser ? $offer->browserCaptureLaunchUrl() : $offer->url"
                    target="_blank"
                    rel="noopener noreferrer"
                    size="sm"
                    icon="heroicon-m-arrow-top-right-on-square"
                    class="justify-center"
                >
                    {{ $canVerifyInBrowser ? ($offer->isOutOfStock() ? 'Revisar stock' : 'Verificar precio') : 'Abrir tienda' }}
                </x-filament::button>
            </div>
            <p class="mt-3 text-[11px] leading-4 text-gray-400">Confirma stock, vendedor, impuestos y garantía antes de comprar.</p>
        </div>
    </div>
</article>

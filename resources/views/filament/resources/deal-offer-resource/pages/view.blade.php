@php
    /** @var App\Models\DealOffer $record */
    $record->loadMissing('dealSearch');
    $history = $record->priceSnapshots()
        ->where(fn ($eligible) => $eligible
            ->where('comparison_eligible', true)
            ->orWhereNull('comparison_eligible'))
        ->orderBy('captured_at')
        ->get();
    $currentPrice = $record->hasVerifiedPrice() ? (float) $record->price : null;
    $minimumPrice = $history->min(fn ($snapshot) => (float) $snapshot->price);
    $maximumPrice = $history->max(fn ($snapshot) => (float) $snapshot->price);
    $previousPrice = $currentPrice === null ? null : $history
        ->reverse()
        ->first(fn ($snapshot) => abs((float) $snapshot->price - $currentPrice) > 0.005)?->price;
    $drop = $previousPrice !== null && (float) $previousPrice > $currentPrice
        ? (float) $previousPrice - $currentPrice
        : null;
    $dropPercent = $drop !== null ? ($drop / (float) $previousPrice) * 100 : null;
    $target = $record->dealSearch?->target_price !== null ? (float) $record->dealSearch->target_price : null;
    $keepaGraph = $record->keepaGraphUrl();
    $keepaUrl = $record->keepaProductUrl();
@endphp

<x-filament-panels::page>
    <div class="grid gap-6 xl:grid-cols-[22rem_minmax(0,1fr)]">
        <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10">
            <x-pc-part-visual
                :src="$record->image_url"
                :alt="$record->title"
                :type="$record->dealSearch?->component_type"
                class="aspect-[4/3] w-full rounded-none border-0 ring-0"
            />
            <div class="space-y-4 p-5">
                <div class="flex flex-wrap items-center gap-2">
                    <x-filament::badge :color="$record->dealSearch?->component_type?->getColor() ?? 'gray'">
                        {{ $record->dealSearch?->component_type?->getLabel() ?? 'Componente' }}
                    </x-filament::badge>
                    @if ($record->isOutOfStock())
                        <x-filament::badge color="danger" icon="heroicon-m-x-circle">Agotado</x-filament::badge>
                    @elseif ($record->availability === App\Models\DealOffer::AVAILABILITY_IN_STOCK)
                        <x-filament::badge color="success" icon="heroicon-m-check-circle">Disponible</x-filament::badge>
                    @else
                        <x-filament::badge color="warning">Stock por confirmar</x-filament::badge>
                    @endif
                </div>

                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-primary-500">{{ $record->store }}</p>
                    <h2 class="mt-1 text-base font-semibold leading-6 text-gray-950 dark:text-white">{{ $record->title }}</h2>
                </div>

                @if ($record->seller)
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Vendedor: <span class="font-medium text-gray-700 dark:text-gray-200">{{ $record->seller }}</span>
                    </p>
                @endif

                <p class="text-xs leading-5 text-gray-500 dark:text-gray-400">
                    Precio, stock y vendedor pueden cambiar. La compra siempre se termina en la tienda.
                </p>
            </div>
        </section>

        <div class="min-w-0 space-y-6">
            <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-2xl bg-gray-950 p-5 text-white ring-1 ring-white/10">
                    <p class="text-xs uppercase tracking-wider text-gray-400">Precio actual</p>
                    <p class="mt-2 text-3xl font-black text-emerald-400">
                        {{ $currentPrice === null ? 'Sin verificar' : '$'.number_format($currentPrice, 2) }}
                    </p>
                </div>
                <div class="rounded-2xl bg-white p-5 ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10">
                    <p class="text-xs uppercase tracking-wider text-gray-500">Mínimo registrado</p>
                    <p class="mt-2 text-2xl font-bold text-gray-950 dark:text-white">
                        {{ $minimumPrice === null ? '—' : '$'.number_format((float) $minimumPrice, 2) }}
                    </p>
                </div>
                <div class="rounded-2xl bg-white p-5 ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10">
                    <p class="text-xs uppercase tracking-wider text-gray-500">Bajó desde antes</p>
                    <p class="mt-2 text-2xl font-bold {{ $drop ? 'text-emerald-500' : 'text-gray-950 dark:text-white' }}">
                        {{ $drop === null ? 'Sin cambio aún' : '-$'.number_format($drop, 2) }}
                    </p>
                    @if ($dropPercent !== null)
                        <p class="mt-1 text-xs font-semibold text-emerald-500">{{ number_format($dropPercent, 1) }}% menos</p>
                    @endif
                </div>
                <div class="rounded-2xl bg-white p-5 ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10">
                    <p class="text-xs uppercase tracking-wider text-gray-500">Tu objetivo</p>
                    <p class="mt-2 text-2xl font-bold text-gray-950 dark:text-white">
                        {{ $target === null ? 'No definido' : '$'.number_format($target, 2) }}
                    </p>
                </div>
            </section>

            @livewire(
                App\Filament\Resources\DealOfferResource\Widgets\DealOfferPriceHistoryChart::class,
                ['record' => $record],
                key('deal-offer-history-'.$record->getKey())
            )

            @if ($history->count() < 2)
                <p class="rounded-xl bg-primary-50 px-4 py-3 text-sm text-primary-700 ring-1 ring-primary-200 dark:bg-primary-950/40 dark:text-primary-300 dark:ring-primary-500/20">
                    PriceBuddy acaba de empezar este historial. Cada verificación futura añadirá un punto nuevo a la gráfica.
                </p>
            @endif

            @if ($keepaGraph)
                <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10">
                    <div class="flex flex-col gap-3 border-b border-gray-200 p-5 dark:border-white/10 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-base font-bold text-gray-950 dark:text-white">Historial anterior de Amazon · Keepa</h2>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Referencia de los últimos 90 días para el ASIN {{ $record->amazonAsin() }}.</p>
                        </div>
                        <x-filament::button
                            tag="a"
                            :href="$keepaUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            color="gray"
                            icon="heroicon-m-arrow-top-right-on-square"
                        >
                            Abrir Keepa
                        </x-filament::button>
                    </div>
                    <a href="{{ $keepaUrl }}" target="_blank" rel="noopener noreferrer" class="block bg-white p-3 sm:p-5">
                        <img
                            src="{{ $keepaGraph }}"
                            alt="Gráfica de historial Keepa para {{ $record->title }}"
                            loading="lazy"
                            referrerpolicy="no-referrer"
                            class="mx-auto h-auto w-full max-w-6xl"
                        />
                    </a>
                </section>
            @endif
        </div>
    </div>
</x-filament-panels::page>

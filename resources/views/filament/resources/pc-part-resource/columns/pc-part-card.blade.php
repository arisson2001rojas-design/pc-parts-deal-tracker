@php
    /** @var App\Models\PcPart $part */
    $product = $part->currentUserProduct;
    $price = (float) ($part->getAttribute('current_price') ?? 0);
    $stores = collect(array_keys($part->retailer_urls ?? []))->map(fn (string $store) => ucfirst($store));
    $lastCheck = data_get($product?->price_cache, '0.last_scrape');
    $bestStore = data_get($product?->price_cache, '0.store_name');
@endphp

<article class="pc-ui-card pc-part-card group grid h-full grid-cols-1 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-950/10 transition hover:shadow-lg dark:bg-gray-900 dark:ring-white/10 sm:grid-cols-[9rem_minmax(0,1fr)]">
    <div class="relative min-h-40 bg-gray-950 sm:min-h-full">
        <x-pc-part-visual
            :src="$product?->image"
            :alt="$part->name"
            :type="$part->component_type"
            class="h-full min-h-40 w-full rounded-none border-0 ring-0"
        />
        <div class="absolute left-2.5 top-2.5">
            <x-filament::badge :color="$part->component_type->getColor()">
                {{ $part->component_type->getLabel() }}
            </x-filament::badge>
        </div>
    </div>

    <div class="flex min-w-0 flex-col p-4">
        <div class="flex min-w-0 items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="truncate text-xs font-medium text-gray-500 dark:text-gray-400" title="{{ $part->manufacturer }}{{ $part->series ? ' · '.$part->series : '' }}">
                    {{ $part->manufacturer ?: 'Fabricante desconocido' }}
                    @if ($part->series)
                        · {{ $part->series }}
                    @endif
                    @if ($part->release_year)
                        · {{ $part->release_year }}
                    @endif
                </p>
                <h3 class="mt-1 line-clamp-2 break-words text-sm font-semibold leading-5 text-gray-950 dark:text-white" title="{{ $part->name }}">
                    {{ $part->name }}
                </h3>
            </div>

            <x-filament::badge class="shrink-0" :color="$product ? 'success' : 'gray'" :icon="$product ? 'heroicon-m-bell' : 'heroicon-m-plus'">
                {{ $product ? 'Monitoreado' : 'Catálogo' }}
            </x-filament::badge>
        </div>

        <div class="mt-3 flex min-h-6 flex-wrap gap-1.5">
            @forelse ($stores as $store)
                <x-filament::badge color="gray" size="sm">{{ $store }}</x-filament::badge>
            @empty
                <span class="text-xs text-gray-400">Sin tienda identificada</span>
            @endforelse
        </div>

        <div class="mt-auto border-t border-gray-200 pt-3 dark:border-white/10">
            @if ($price > 0)
                <p class="text-2xl font-bold tracking-tight text-success-600 dark:text-success-400">
                    {{ App\Services\Helpers\CurrencyHelper::toString($price) }}
                </p>
                <p class="mt-1 line-clamp-1 text-xs text-gray-500 dark:text-gray-400" title="{{ $bestStore }}{{ $lastCheck ? ' · '.Illuminate\Support\Carbon::parse($lastCheck)->diffForHumans() : '' }}">
                    {{ $bestStore ?: 'Mejor tienda comprobada' }}
                    @if ($lastCheck)
                        · revisado {{ Illuminate\Support\Carbon::parse($lastCheck)->diffForHumans() }}
                    @endif
                </p>
            @elseif ($product)
                <p class="font-semibold text-amber-600 dark:text-amber-400">Comprobación pendiente</p>
                <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">Las tiendas se revisan automáticamente cada 8 horas.</p>
            @else
                <p class="font-semibold text-gray-700 dark:text-gray-200">Listo para monitorear</p>
                <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">Usa «Monitorear precios» para iniciar las revisiones.</p>
            @endif
        </div>
    </div>
</article>

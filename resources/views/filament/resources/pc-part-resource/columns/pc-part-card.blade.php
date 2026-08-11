@php
    /** @var App\Models\PcPart $part */
    $product = $part->currentUserProduct;
    $price = (float) ($part->getAttribute('current_price') ?? 0);
    $stores = collect(array_keys($part->retailer_urls ?? []))->map(fn (string $store) => ucfirst($store));
    $lastCheck = data_get($product?->price_cache, '0.last_scrape');
    $bestStore = data_get($product?->price_cache, '0.store_name');
@endphp

<article class="pc-deal-card flex h-full min-h-[24rem] flex-col overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-950/10 transition hover:-translate-y-0.5 hover:shadow-lg dark:bg-gray-900 dark:ring-white/10">
    <div class="relative">
        <x-pc-part-visual
            :src="$product?->image"
            :alt="$part->name"
            :type="$part->component_type"
            class="h-36 w-full rounded-none border-0 ring-0"
        />
        <div class="absolute left-3 top-3">
            <x-filament::badge :color="$part->component_type->getColor()">
                {{ $part->component_type->getLabel() }}
            </x-filament::badge>
        </div>
        <div class="absolute right-3 top-3">
            <x-filament::badge :color="$product ? 'success' : 'gray'" :icon="$product ? 'heroicon-m-bell' : 'heroicon-m-plus'">
                {{ $product ? 'Tracking' : 'Catalog' }}
            </x-filament::badge>
        </div>
    </div>

    <div class="flex flex-1 flex-col p-5">
        <div class="mb-2 flex flex-wrap items-center gap-x-2 text-xs text-gray-500 dark:text-gray-400">
            <span>{{ $part->manufacturer ?: 'Unknown maker' }}</span>
            @if ($part->release_year)
                <span>• {{ $part->release_year }}</span>
            @endif
            @if ($part->series)
                <span>• {{ $part->series }}</span>
            @endif
        </div>

        <h3 class="line-clamp-3 text-base font-semibold leading-6 text-gray-950 dark:text-white">
            {{ $part->name }}
        </h3>

        <div class="mt-4 flex flex-wrap gap-1.5">
            @forelse ($stores as $store)
                <x-filament::badge color="gray" size="sm">{{ $store }}</x-filament::badge>
            @empty
                <span class="text-xs text-gray-400">No store identifier</span>
            @endforelse
        </div>

        <div class="mt-auto border-t border-gray-200 pt-4 dark:border-white/10">
            @if ($price > 0)
                <p class="text-2xl font-bold tracking-tight text-success-600 dark:text-success-400">
                    {{ App\Services\Helpers\CurrencyHelper::toString($price) }}
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ $bestStore ?: 'Best checked store' }}
                    @if ($lastCheck)
                        • checked {{ Illuminate\Support\Carbon::parse($lastCheck)->diffForHumans() }}
                    @endif
                </p>
            @elseif ($product)
                <p class="font-semibold text-amber-600 dark:text-amber-400">Price check pending</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">The stores will be checked automatically about every 8 hours.</p>
            @else
                <p class="font-semibold text-gray-700 dark:text-gray-200">Ready to track</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Choose Track prices to start 8-hour checks.</p>
            @endif
        </div>
    </div>
</article>

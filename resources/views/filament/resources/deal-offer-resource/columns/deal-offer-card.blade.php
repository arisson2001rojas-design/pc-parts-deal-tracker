@php
    /** @var App\Models\DealOffer $offer */
    $search = $offer->dealSearch;
    $type = $search?->component_type;
    $source = match ($offer->source) {
        'best_buy_api' => 'Best Buy API',
        'dealnews_rss' => 'DealNews',
        default => 'Web index',
    };
    $underTarget = $offer->price !== null
        && $search?->target_price !== null
        && (float) $offer->price <= (float) $search->target_price;
@endphp

<article class="flex h-full min-h-[28rem] flex-col overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-950/10 transition hover:-translate-y-0.5 hover:shadow-lg dark:bg-gray-900 dark:ring-white/10">
    <div class="relative">
        <x-pc-part-visual
            :src="$offer->image_url"
            :alt="$offer->title"
            :type="$type"
            class="h-44 w-full rounded-none border-0 ring-0"
        />

        <div class="absolute left-3 top-3 flex flex-wrap gap-2">
            <x-filament::badge :color="$type?->getColor() ?? 'gray'">
                {{ $type?->getLabel() ?? 'PC part' }}
            </x-filament::badge>
            @if ($underTarget)
                <x-filament::badge color="success" icon="heroicon-m-arrow-trending-down">
                    Under target
                </x-filament::badge>
            @endif
        </div>
    </div>

    <div class="flex flex-1 flex-col p-5">
        <div class="mb-3 flex items-center justify-between gap-3 text-xs text-gray-500 dark:text-gray-400">
            <span class="font-semibold text-gray-700 dark:text-gray-200">{{ $offer->store }}</span>
            <span>{{ $offer->fetched_at?->diffForHumans() }}</span>
        </div>

        <h3 class="line-clamp-3 text-base font-semibold leading-6 text-gray-950 dark:text-white">
            {{ $offer->title }}
        </h3>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Hunt: {{ $search?->name }}</p>

        <div class="mt-auto pt-5">
            @if ($offer->price !== null)
                <p class="text-3xl font-bold tracking-tight text-success-600 dark:text-success-400">
                    ${{ number_format((float) $offer->price, 2) }}
                </p>
                @if ($underTarget)
                    <p class="mt-1 text-xs font-medium text-success-600 dark:text-success-400">
                        ${{ number_format((float) $search->target_price - (float) $offer->price, 2) }} below your alert
                    </p>
                @endif
            @else
                <p class="text-lg font-semibold text-gray-700 dark:text-gray-200">Confirm price in store</p>
            @endif

            <div class="mt-4 flex items-center justify-between gap-3">
                <span class="text-xs text-gray-500 dark:text-gray-400">Source: {{ $source }}</span>
                <x-filament::button
                    tag="a"
                    :href="$offer->url"
                    target="_blank"
                    rel="noopener noreferrer"
                    size="sm"
                    icon="heroicon-m-arrow-top-right-on-square"
                >
                    Check price
                </x-filament::button>
            </div>
            <p class="mt-3 text-[11px] leading-4 text-gray-400">Verify seller, stock and final price before buying.</p>
        </div>
    </div>
</article>

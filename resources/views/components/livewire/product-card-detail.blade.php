@php
    use App\Enums\Trend;
    /** @var \App\Models\Product $product */
    $latestPrice = $product->getPriceCache()->first();
    $hasUsefulHistory = $product->hasComparablePriceHistory();
    $extraClasses = $standalone
        ? 'rounded-lg bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 w-full max-w-60 lg:max-w-md'
        : 'min-w-0 rounded-xl';
@endphp
<div
    class="product-card-detail w-full {{ $extraClasses }}"
>
    @if ($showChart)
        <button
            @click="expanded = !expanded"
            type="button"
            class="grid w-full cursor-pointer grid-cols-[minmax(0,1fr)_auto] items-center gap-3 rounded-xl bg-gray-50 p-3 text-left ring-1 ring-gray-950/5 transition hover:bg-gray-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 dark:bg-white/5 dark:ring-white/10 dark:hover:bg-white/10"
            :aria-expanded="expanded"
        >
            <div class="min-w-0">
                @if ($latestPrice?->hasVisiblePrice())
                    <div class="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-1">
                        <span class="text-xl font-bold text-gray-950 dark:text-white">{{ $latestPrice->getUnitPriceFormatted() }}</span>
                        <span class="truncate text-xs font-semibold text-gray-500 dark:text-gray-400">{{ '@'.$latestPrice->getStoreName() }}</span>
                    </div>
                    <p class="mt-1 truncate text-xs text-gray-500 dark:text-gray-400">
                        @if ($latestPrice->getLastScrapeDate())
                            Revisado {{ $latestPrice->getLastScrapeDate()->diffForHumans() }}
                        @else
                            Última comprobación no disponible
                        @endif
                    </p>
                @elseif ($latestPrice?->isUnavailable())
                    <p class="font-semibold text-gray-700 dark:text-gray-200">No disponible</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Último estado conocido en {{ $latestPrice->getStoreName() }}</p>
                @else
                    <p class="font-semibold text-gray-700 dark:text-gray-200">Sin precio verificado</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Abre los detalles para añadir o revisar tiendas.</p>
                @endif
            </div>

            <div class="flex items-center gap-2">
                @if ($hasUsefulHistory)
                    <div class="hidden h-10 w-36 overflow-hidden rounded-lg bg-custom-400/10 sm:block">
                        <x-range-chart :product="$product" height="40px" class="rounded-lg" />
                    </div>
                @endif
                <x-filament::icon icon="heroicon-m-chevron-down" class="h-5 w-5 text-gray-400 transition" x-bind:class="expanded ? 'rotate-180' : ''" />
            </div>
        </button>
    @endif
    <div x-cloak x-show="expanded">

        <div class="pt-1 px-2">
            @include('components.prices-column', ['items' => $product->price_cache])
        </div>

        <div class="pb-expandable-stat__actions flex flex-wrap items-center justify-start gap-2 px-2 pb-2 py-1 text-gray-500 dark:text-gray-400">
            {{ $this->addUrlAction }}
            {{ $this->editAction }}
            {{ $this->fetchAction }}
            {{ $this->deleteAction }}
        </div>

        @php($nextCheckLabel = $showNextCheck ? null : ($product->nextCheckShortLabel() ?? ($product->paused ? __('Paused') : null)))

        @include('components.price-aggregates', ['aggregates' => $product->price_aggregates, 'trend' => $product->trend, 'age' => $product->first_scrape_date, 'nextCheck' => $nextCheckLabel])

        @if ($showNextCheck)
            @include('components.next-check-countdown', ['product' => $product])
        @endif
    </div>

    <x-filament-actions::modals />
</div>

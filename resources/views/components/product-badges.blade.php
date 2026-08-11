@php
    $product = $product ?? $getRecord();
    $latestPrice = $product->getPriceCache()->first();
    $verdict = data_get($product->insights_cache, 'dealScore.verdict');
    $verdictKey = data_get($product->insights_cache, 'dealScore.verdictKey');
    $lowConfidence = (bool) data_get($product->insights_cache, 'dealScore.lowConfidence', false);
    $verdictColor = match ($verdictKey) {
        'great', 'good' => 'success',
        'average', 'unknown' => 'gray',
        'pricey' => 'warning',
        'wait' => 'danger',
        default => 'gray',
    };
    // "Not enough data yet" already says it has no history; the generic low-confidence
    // hover would just repeat itself.
    $verdictHover = $verdictKey === 'unknown'
        ? __('The price has not moved yet, so there is nothing to compare it against')
        : ($lowConfidence ? __('Not enough price history for a confident verdict') : $verdict);
    $verdictLabel = $lowConfidence ? 'Sin historial suficiente' : $verdict;
    $stockLabel = match ($latestPrice?->getStockStatus()->value) {
        'pre_order' => 'Preventa',
        'back_order' => 'Pedido pendiente',
        'special_order' => 'Pedido especial',
        'out_of_stock' => 'Agotado',
        'discontinued' => 'Descontinuado',
        default => $latestPrice?->getStockStatusLabel(),
    };
@endphp
@if (! $product->is_last_scrape_successful || $product->is_notified_price || $latestPrice?->isUnavailable() || $product->paused || $verdict)
    <div {{ $attributes->merge(['class' => 'inline-flex items-center gap-2 mt-1 flex-wrap']) }}>
        @if ($latestPrice?->isUnavailable())
            <div class="whitespace-nowrap" data-status-priority="primary">
                @include('components.icon-badge', [
                    'hoverText' => 'El último estado conocido es '.strtolower($stockLabel),
                    'label' => $stockLabel,
                    'color' => $latestPrice->getStockStatusColor(),
                    'icon' => $latestPrice->getStockStatusIcon(),
                ])
            </div>
        @endif

        @if (! $product->is_last_scrape_successful)
            <div class="whitespace-nowrap" data-status-priority="primary">
                @include('components.icon-badge', [
                    'hoverText' => 'Una o más URL fallaron en la última revisión; el precio mostrado puede ser anterior',
                    'label' => 'Error de revisión',
                    'color' => 'warning',
                    'icon' => 'heroicon-m-exclamation-triangle',
                ])
            </div>
        @endif

        @if ($product->is_notified_price)
            <div class="whitespace-nowrap" data-status-priority="primary">
                @include('components.icon-badge', [
                    'hoverText' => 'El precio coincide con tu objetivo',
                    'label' => 'Precio objetivo',
                    'color' => 'success',
                    'icon' => 'heroicon-m-shopping-bag',
                ])
            </div>
        @endif

        @if ($verdict && ! $product->is_notified_price)
            <div class="whitespace-nowrap" data-status-priority="secondary" data-verdict-color="{{ $lowConfidence ? 'gray' : $verdictColor }}">
                @include('components.icon-badge', [
                    'hoverText' => ! $product->is_last_scrape_successful && ! $lowConfidence
                        ? $verdictHover.' · Basado en el último precio conocido'
                        : $verdictHover,
                    'label' => $verdictLabel,
                    'color' => $lowConfidence ? 'gray' : $verdictColor,
                    'icon' => $lowConfidence ? 'heroicon-m-question-mark-circle' : 'heroicon-m-sparkles',
                ])
            </div>
        @endif

        @if ($product->paused)
            <div class="whitespace-nowrap opacity-80" data-status-priority="tertiary">
                @include('components.icon-badge', [
                    'hoverText' => 'Las comprobaciones automáticas están pausadas para este producto',
                    'label' => 'Pausado',
                    'color' => 'gray',
                    'icon' => 'heroicon-m-pause',
                ])
            </div>
        @endif
    </div>
@endif

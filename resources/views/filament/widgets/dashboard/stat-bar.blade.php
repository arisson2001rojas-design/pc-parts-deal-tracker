<div class="mb-8 grid grid-cols-2 gap-4 md:grid-cols-5">
    @php($cells = [
        ['label' => 'Monitoreados', 'value' => $stats['tracked'], 'hint' => $stats['comparable'].' con historial comparable'],
        ['label' => 'Mínimo histórico', 'value' => $stats['atLowest'], 'hint' => 'Solo con cambios de precio'],
        ['label' => 'En o bajo promedio', 'value' => $stats['belowAverage'], 'hint' => 'Incluye mínimos históricos'],
        ['label' => 'Agotados', 'value' => $stats['outOfStock'], 'hint' => 'Estado más reciente'],
        ['label' => 'Ahorro potencial', 'value' => '$'.number_format($stats['potentialSavings'], 2), 'hint' => 'Frente al promedio conocido'],
    ])
    @foreach ($cells as $cell)
        <div class="fi-wi-stats-overview-stat rounded-xl bg-white dark:bg-gray-900 ring-1 ring-gray-950/5 dark:ring-white/10 p-4">
            <div class="text-sm text-gray-500 dark:text-gray-400">{{ $cell['label'] }}</div>
            <div class="text-2xl font-bold text-gray-950 dark:text-white">{{ $cell['value'] }}</div>
            <div class="mt-1 text-[11px] leading-4 text-gray-400 dark:text-gray-500">{{ $cell['hint'] }}</div>
        </div>
    @endforeach
</div>

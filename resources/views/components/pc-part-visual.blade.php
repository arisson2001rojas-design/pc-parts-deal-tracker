@props([
    'src' => null,
    'alt' => 'PC component',
    'type' => null,
])

@php
    use App\Enums\ComponentType;

    $componentType = $type instanceof ComponentType
        ? $type
        : ComponentType::tryFrom((string) $type);
    $label = $componentType?->getLabel() ?? 'PC part';
    $icon = match ($componentType) {
        ComponentType::Cpu => 'heroicon-o-cpu-chip',
        ComponentType::Gpu => 'heroicon-o-rectangle-group',
        ComponentType::Ram => 'heroicon-o-server-stack',
        ComponentType::Ssd => 'heroicon-o-circle-stack',
        ComponentType::Psu => 'heroicon-o-bolt',
        default => 'heroicon-o-wrench-screwdriver',
    };
    $accent = match ($componentType) {
        ComponentType::Cpu => 'from-primary-500/25 via-primary-950 to-gray-950 text-primary-300',
        ComponentType::Gpu => 'from-emerald-500/25 via-emerald-950 to-gray-950 text-emerald-300',
        ComponentType::Ram => 'from-sky-500/25 via-sky-950 to-gray-950 text-sky-300',
        ComponentType::Ssd => 'from-amber-500/25 via-amber-950 to-gray-950 text-amber-300',
        ComponentType::Psu => 'from-rose-500/25 via-rose-950 to-gray-950 text-rose-300',
        default => 'from-gray-500/20 via-gray-900 to-gray-950 text-gray-300',
    };
    $hasImage = filled($src);
@endphp

<div {{ $attributes->class(['relative isolate overflow-hidden rounded-xl bg-gray-950 ring-1 ring-white/10']) }}>
    @if ($hasImage)
        <img
            src="{{ $src }}"
            alt="{{ $alt }}"
            loading="lazy"
            decoding="async"
            referrerpolicy="no-referrer"
            class="absolute inset-0 h-full w-full bg-white object-contain p-4"
            onerror="this.classList.add('hidden'); this.nextElementSibling.classList.remove('hidden'); this.nextElementSibling.classList.add('flex');"
        />
    @endif

    <div class="{{ $hasImage ? 'hidden' : 'flex' }} h-full min-h-32 w-full flex-col items-center justify-center gap-3 bg-gradient-to-br p-6 {{ $accent }}">
        <x-filament::icon :icon="$icon" class="h-14 w-14" />
        <span class="text-xs font-semibold uppercase tracking-[0.2em]">{{ $label }}</span>
    </div>
</div>

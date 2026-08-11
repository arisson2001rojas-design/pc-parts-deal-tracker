<?php

namespace App\Filament\Pages;

use App\Filament\Resources\DealOfferResource;
use App\Filament\Resources\DealOfferResource\Widgets\DealHunterStats;
use App\Filament\Resources\ProductResource\Actions\CreateAction;
use App\Filament\Widgets\ProductStats;
use App\Services\Dashboard\DashboardLayoutService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Support\Facades\FilamentIcon;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Contracts\Support\Htmlable;

class HomeDashboard extends Page
{
    protected static string $routePath = '/';

    /**
     * Human labels for the toggleable dashboard sections.
     *
     * @var array<string, string>
     */
    private const SECTION_LABELS = [
        'stat_bar' => 'Resumen de precios',
        'buy_now' => 'Qué conviene comprar ahora',
        'recently_dropped' => 'Bajaron recientemente',
        'needs_attention' => 'Necesitan atención',
    ];

    protected static ?int $navigationSort = -2;

    /**
     * @var view-string
     */
    protected static string $view = 'filament-panels::pages.dashboard';

    public static function getNavigationLabel(): string
    {
        return 'Inicio';
    }

    public static function getNavigationIcon(): string|Htmlable|null
    {
        return static::$navigationIcon
            ?? FilamentIcon::resolve('panels::pages.dashboard.navigation-item')
            ?? (Filament::hasTopNavigation() ? 'heroicon-m-home' : 'heroicon-o-home');
    }

    public static function getRoutePath(): string
    {
        return static::$routePath;
    }

    /**
     * @return array<class-string<Widget> | WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        return [
            DealHunterStats::class,
            ProductStats::class,
        ];
    }

    /**
     * @return array<class-string<Widget> | WidgetConfiguration>
     */
    public function getVisibleWidgets(): array
    {
        return $this->filterVisibleWidgets($this->getWidgets());
    }

    /**
     * @return int | string | array<string, int | string | null>
     */
    public function getColumns(): int|string|array
    {
        return 1;
    }

    public function getTitle(): string|Htmlable
    {
        return 'Centro de caza PC Gaming';
    }

    public function getHeaderActions(): array
    {
        return [
            Action::make('deal_hunter')
                ->label('Abrir radar de ofertas')
                ->icon('heroicon-o-fire')
                ->url(DealOfferResource::getUrl('index')),
            CreateAction::make()->label('Añadir producto'),
            $this->customizeAction(),
        ];
    }

    protected function customizeAction(): Action
    {
        return Action::make('customize')
            ->label('Personalizar')
            ->icon('heroicon-o-adjustments-horizontal')
            ->color('gray')
            ->modalHeading('Personalizar inicio')
            ->modalSubmitActionLabel('Guardar')
            ->fillForm(fn (): array => $this->currentSectionVisibility())
            ->form(
                array_map(
                    fn (string $key): Toggle => Toggle::make($key)->label(__(self::SECTION_LABELS[$key])),
                    DashboardLayoutService::SECTION_KEYS,
                )
            )
            ->action(function (array $data): void {
                $layout = new DashboardLayoutService(auth()->user());

                foreach (DashboardLayoutService::SECTION_KEYS as $key) {
                    $layout->setSectionVisible($key, (bool) ($data[$key] ?? false));
                }

                $this->dispatch('dashboard-sections-updated');
            });
    }

    /**
     * @return array<string, bool>
     */
    protected function currentSectionVisibility(): array
    {
        $layout = new DashboardLayoutService(auth()->user());

        $visibility = [];
        foreach (DashboardLayoutService::SECTION_KEYS as $key) {
            $visibility[$key] = $layout->isSectionVisible($key);
        }

        return $visibility;
    }
}

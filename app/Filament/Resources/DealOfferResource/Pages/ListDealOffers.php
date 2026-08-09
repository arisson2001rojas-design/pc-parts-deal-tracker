<?php

namespace App\Filament\Resources\DealOfferResource\Pages;

use App\Enums\ComponentType;
use App\Filament\Resources\DealOfferResource;
use App\Filament\Resources\DealOfferResource\Widgets\DealHunterStats;
use App\Jobs\RefreshDealSearchJob;
use App\Models\DealOffer;
use App\Models\DealSearch;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListDealOffers extends ListRecords
{
    protected static string $resource = DealOfferResource::class;

    public function mount(): void
    {
        parent::mount();

        $cutoff = now()->subHours((int) config('deal_hunter.refresh_hours', 6));
        DealSearch::query()
            ->currentUser()
            ->where('enabled', true)
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('last_searched_at')
                ->orWhere('last_searched_at', '<=', $cutoff)
            )
            ->pluck('id')
            ->each(fn (int $id) => RefreshDealSearchJob::dispatch($id));
    }

    public function getTabs(): array
    {
        return [
            'recent' => Tab::make('Más baratas · 7 días')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereIn('source', DealOffer::VERIFIED_PRICE_SOURCES)
                    ->whereNotNull('price')
                    ->where('availability', '!=', DealOffer::AVAILABILITY_OUT_OF_STOCK)
                    ->where('fetched_at', '>=', now()->subDays(7))
                ),
            'today' => Tab::make('Verificadas hoy')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereIn('source', DealOffer::VERIFIED_PRICE_SOURCES)
                    ->whereNotNull('price')
                    ->where('availability', '!=', DealOffer::AVAILABILITY_OUT_OF_STOCK)
                    ->whereDate('fetched_at', today())
                ),
            'target' => Tab::make('Bajo mi objetivo')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereIn('source', DealOffer::VERIFIED_PRICE_SOURCES)
                    ->whereNotNull('price')
                    ->where('availability', '!=', DealOffer::AVAILABILITY_OUT_OF_STOCK)
                    ->whereHas('dealSearch', fn (Builder $search): Builder => $search
                        ->whereNotNull('target_price')
                        ->whereColumn('deal_offers.price', '<=', 'deal_searches.target_price')
                    )
                ),
            'all' => Tab::make('Todo el radar'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'recent';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('new_hunt')
                ->label('Nueva cacería')
                ->icon('heroicon-o-plus')
                ->form([
                    Forms\Components\TextInput::make('name')
                        ->label('Nombre de la cacería')
                        ->placeholder('Ej. GPU 1440p barata')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('query')
                        ->label('Componente exacto')
                        ->placeholder('Ej. Radeon RX 7800 XT')
                        ->helperText('Incluye marca y modelo para evitar resultados parecidos.')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\Select::make('component_type')
                        ->label('Tipo')
                        ->options(collect(ComponentType::cases())->mapWithKeys(
                            fn (ComponentType $type): array => [$type->value => $type->getLabel()]
                        )->all())
                        ->required(),
                    Forms\Components\TextInput::make('target_price')
                        ->label('Precio objetivo')
                        ->prefix('$')
                        ->numeric(),
                ])
                ->action(function (array $data): void {
                    $search = DealSearch::query()->create($data + [
                        'user_id' => auth()->id(),
                        'enabled' => true,
                    ]);
                    RefreshDealSearchJob::dispatch($search->getKey());
                    Notification::make()->title('Cacería iniciada')->body('Ya estamos buscando en todas las tiendas.')->success()->send();
                }),
            Actions\Action::make('refresh')
                ->label('Rastrear ahora')
                ->icon('heroicon-o-arrow-path')
                ->action(function (): void {
                    DealSearch::query()
                        ->currentUser()
                        ->where('enabled', true)
                        ->pluck('id')
                        ->each(fn (int $id) => RefreshDealSearchJob::dispatch($id));
                    Notification::make()->title('Rastreo iniciado')->body('Las cacerías activas se actualizarán en segundo plano.')->success()->send();
                }),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [DealHunterStats::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}

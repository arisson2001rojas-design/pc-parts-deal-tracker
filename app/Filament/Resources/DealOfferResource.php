<?php

namespace App\Filament\Resources;

use App\Enums\ComponentType;
use App\Filament\Resources\DealOfferResource\Columns\DealOfferCardColumn;
use App\Filament\Resources\DealOfferResource\Pages;
use App\Models\DealOffer;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Resources\Resource;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DealOfferResource extends Resource
{
    protected static ?string $model = DealOffer::class;

    protected static ?string $navigationIcon = 'heroicon-o-fire';

    protected static ?string $navigationLabel = 'Cazador de ofertas';

    protected static ?string $navigationGroup = 'PC Gaming';

    protected static ?string $modelLabel = 'oferta';

    protected static ?string $pluralModelLabel = 'ofertas';

    protected static ?int $navigationSort = 1;

    public static function table(Table $table): Table
    {
        return $table
            ->heading('Radar de ofertas PC Gaming')
            ->description('Precios en USD verificados en tiendas de Estados Unidos. PriceBuddy separa descubrimientos, precios confirmados y productos agotados.')
            ->columns([
                DealOfferCardColumn::make('title')
                    ->label('Oferta')
                    ->searchable(['title', 'store'])
                    ->sortable(),
            ])
            ->contentGrid([
                'default' => 1,
            ])
            ->filters([
                SelectFilter::make('store')
                    ->label('Tienda')
                    ->options(collect(config('deal_hunter.retailers', []))->pluck('name', 'name')->all()),
                SelectFilter::make('component_type')
                    ->label('Componente')
                    ->options(collect(ComponentType::cases())->mapWithKeys(
                        fn (ComponentType $type): array => [$type->value => $type->getLabel()]
                    )->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        return $query->when(
                            filled($value),
                            fn (Builder $query): Builder => $query->whereHas(
                                'dealSearch',
                                fn (Builder $search): Builder => $search->where('component_type', $value)
                            )
                        );
                    }),
                SelectFilter::make('availability')
                    ->label('Disponibilidad')
                    ->options([
                        DealOffer::AVAILABILITY_IN_STOCK => 'Disponible',
                        DealOffer::AVAILABILITY_UNKNOWN => 'Por confirmar',
                        DealOffer::AVAILABILITY_OUT_OF_STOCK => 'Agotado',
                    ]),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByRaw("CASE availability WHEN 'in_stock' THEN 0 WHEN 'unknown' THEN 1 ELSE 2 END")
                ->orderByRaw('CASE WHEN price IS NULL THEN 1 ELSE 0 END')
                ->orderBy('price')
            )
            ->recordUrl(null)
            ->poll('10s')
            ->paginated(AdminPanelProvider::DEFAULT_PAGINATION)
            ->emptyStateHeading('Buscando ofertas para tu PC')
            ->emptyStateDescription('Crea una cacería o actualiza las búsquedas. Los resultados aparecerán en cuanto termine el rastreo.')
            ->emptyStateIcon('heroicon-o-fire');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->currentUser()
            ->with(['dealSearch', 'priceSnapshots']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDealOffers::route('/'),
            'view' => Pages\ViewDealOffer::route('/{record}'),
        ];
    }
}

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

    protected static ?string $navigationLabel = 'Deal hunter';

    protected static ?string $navigationGroup = 'PC deals';

    protected static ?string $modelLabel = 'deal';

    protected static ?string $pluralModelLabel = 'deal hunter';

    protected static ?int $navigationSort = 1;

    public static function table(Table $table): Table
    {
        return $table
            ->heading('Best PC component deals found today')
            ->description('Fresh discoveries from a deals feed, a web index, and official APIs. Always confirm the current price, seller, stock, tax, and warranty before buying.')
            ->columns([
                DealOfferCardColumn::make('title')
                    ->label('Offer')
                    ->searchable(['title', 'store'])
                    ->sortable(),
            ])
            ->contentGrid([
                'md' => 2,
                'xl' => 3,
            ])
            ->filters([
                SelectFilter::make('store')
                    ->options(collect(config('deal_hunter.retailers', []))->pluck('name', 'name')->all()),
                SelectFilter::make('component_type')
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
            ])
            ->defaultSort('price')
            ->poll('10s')
            ->paginated(AdminPanelProvider::DEFAULT_PAGINATION)
            ->emptyStateHeading("Searching for today's PC deals")
            ->emptyStateDescription('Create a saved hunt or refresh the starter searches. Results appear here as the background search finishes.')
            ->emptyStateIcon('heroicon-o-fire');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->currentUser()
            ->with('dealSearch');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListDealOffers::route('/')];
    }
}

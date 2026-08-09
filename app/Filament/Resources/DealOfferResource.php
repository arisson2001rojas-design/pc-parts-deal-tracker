<?php

namespace App\Filament\Resources;

use App\Enums\ComponentType;
use App\Filament\Resources\DealOfferResource\Pages;
use App\Models\DealOffer;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Resources\Resource;
use Filament\Tables;
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
            ->description('Prices come from an official deals RSS feed, a web index, or an official API. Confirm price, stock, seller, shipping, taxes, and warranty before buying.')
            ->columns([
                Tables\Columns\TextColumn::make('dealSearch.component_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (ComponentType $state): string => $state->getLabel())
                    ->color(fn (ComponentType $state): string => $state->getColor()),
                Tables\Columns\TextColumn::make('title')
                    ->label('Offer')
                    ->searchable()
                    ->wrap()
                    ->description(fn (DealOffer $record): string => $record->dealSearch->name),
                Tables\Columns\TextColumn::make('store')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('price')
                    ->label('US price')
                    ->money('USD')
                    ->weight('bold')
                    ->color('success')
                    ->sortable()
                    ->placeholder('Open store'),
                Tables\Columns\TextColumn::make('source')
                    ->label('Price source')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'best_buy_api' => 'Best Buy API',
                        'dealnews_rss' => 'DealNews RSS',
                        default => 'Web index',
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'best_buy_api' => 'success',
                        'dealnews_rss' => 'info',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('fetched_at')
                    ->label('Found')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('store')
                    ->options(collect(config('deal_hunter.retailers', []))->pluck('name', 'name')->all()),
                SelectFilter::make('component_type')
                    ->relationship('dealSearch', 'component_type')
                    ->options(collect(ComponentType::cases())->mapWithKeys(
                        fn (ComponentType $type): array => [$type->value => $type->getLabel()]
                    )->all()),
            ])
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label('Check and buy')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (DealOffer $record): string => $record->url)
                    ->openUrlInNewTab(),
            ])
            ->defaultSort('price')
            ->poll('15s')
            ->paginated(AdminPanelProvider::DEFAULT_PAGINATION)
            ->emptyStateHeading('Searching for today’s PC deals')
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

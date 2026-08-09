<?php

namespace App\Filament\Resources;

use App\Enums\ComponentType;
use App\Filament\Resources\DealSearchResource\Pages;
use App\Jobs\RefreshDealSearchJob;
use App\Models\DealSearch;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DealSearchResource extends Resource
{
    protected static ?string $model = DealSearch::class;

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static ?string $navigationLabel = 'Saved hunts';

    protected static ?string $navigationGroup = 'PC deals';

    protected static ?string $modelLabel = 'saved hunt';

    protected static ?string $pluralModelLabel = 'saved hunts';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Name')
                ->required()
                ->maxLength(255),
            Forms\Components\TextInput::make('query')
                ->label('Exact component to hunt')
                ->helperText('Use a specific model, capacity, and variant for cleaner price comparisons.')
                ->required()
                ->maxLength(255),
            Forms\Components\Select::make('component_type')
                ->label('Component type')
                ->options(collect(ComponentType::cases())->mapWithKeys(
                    fn (ComponentType $type): array => [$type->value => $type->getLabel()]
                )->all())
                ->required(),
            Forms\Components\TextInput::make('target_price')
                ->label('Alert price')
                ->prefix('$')
                ->numeric()
                ->minValue(1)
                ->helperText('You will be notified when a discovered price reaches this amount.'),
            Forms\Components\Toggle::make('enabled')
                ->label('Refresh automatically')
                ->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('component_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (ComponentType $state): string => $state->getLabel())
                    ->color(fn (ComponentType $state): string => $state->getColor()),
                Tables\Columns\TextColumn::make('name')
                    ->label('Hunt')
                    ->searchable()
                    ->sortable()
                    ->description(fn (DealSearch $record): string => $record->query),
                Tables\Columns\TextColumn::make('target_price')
                    ->label('Alert at')
                    ->money('USD')
                    ->placeholder('No target'),
                Tables\Columns\TextColumn::make('offers_min_price')
                    ->label('Best found')
                    ->money('USD')
                    ->placeholder('Waiting'),
                Tables\Columns\TextColumn::make('last_searched_at')
                    ->label('Last search')
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder('Never'),
                Tables\Columns\IconColumn::make('enabled')
                    ->label('Automatic')
                    ->boolean(),
            ])
            ->actions([
                Tables\Actions\Action::make('refresh')
                    ->label('Search now')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (DealSearch $record): void {
                        RefreshDealSearchJob::dispatch($record->getKey());
                        Notification::make()->title('Deal search queued')->success()->send();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('name')
            ->poll('15s')
            ->paginated(AdminPanelProvider::DEFAULT_PAGINATION);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->currentUser()
            ->withMin(['offers' => fn (Builder $query): Builder => $query->whereNotNull('price')], 'price');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDealSearches::route('/'),
            'create' => Pages\CreateDealSearch::route('/create'),
            'edit' => Pages\EditDealSearch::route('/{record}/edit'),
        ];
    }
}

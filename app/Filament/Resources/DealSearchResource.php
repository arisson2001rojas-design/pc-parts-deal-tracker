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

    protected static ?string $navigationLabel = 'Mis cacerías';

    protected static ?string $navigationGroup = 'PC Gaming';

    protected static ?string $modelLabel = 'cacería';

    protected static ?string $pluralModelLabel = 'cacerías';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Nombre')
                ->required()
                ->maxLength(255),
            Forms\Components\TextInput::make('query')
                ->label('Componente exacto')
                ->helperText('Usa marca, modelo, capacidad y variante para comparar productos equivalentes.')
                ->required()
                ->maxLength(255),
            Forms\Components\Select::make('component_type')
                ->label('Tipo de componente')
                ->options(collect(ComponentType::cases())->mapWithKeys(
                    fn (ComponentType $type): array => [$type->value => $type->getLabel()]
                )->all())
                ->required(),
            Forms\Components\TextInput::make('target_price')
                ->label('Precio objetivo')
                ->prefix('$')
                ->numeric()
                ->minValue(1)
                ->helperText('Recibirás una alerta cuando un precio verificado llegue a este monto.'),
            Forms\Components\Toggle::make('enabled')
                ->label('Rastrear automáticamente')
                ->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('component_type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (ComponentType $state): string => $state->getLabel())
                    ->color(fn (ComponentType $state): string => $state->getColor()),
                Tables\Columns\TextColumn::make('name')
                    ->label('Cacería')
                    ->searchable()
                    ->sortable()
                    ->description(fn (DealSearch $record): string => $record->query),
                Tables\Columns\TextColumn::make('target_price')
                    ->label('Objetivo')
                    ->money('USD')
                    ->placeholder('Sin objetivo'),
                Tables\Columns\TextColumn::make('offers_min_price')
                    ->label('Mejor precio')
                    ->money('USD')
                    ->placeholder('Esperando'),
                Tables\Columns\TextColumn::make('last_searched_at')
                    ->label('Último rastreo')
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder('Nunca'),
                Tables\Columns\IconColumn::make('enabled')
                    ->label('Automática')
                    ->boolean(),
            ])
            ->actions([
                Tables\Actions\Action::make('refresh')
                    ->label('Rastrear ahora')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (DealSearch $record): void {
                        RefreshDealSearchJob::dispatch($record->getKey());
                        Notification::make()->title('Rastreo iniciado')->success()->send();
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

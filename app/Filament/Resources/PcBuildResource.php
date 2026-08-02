<?php

namespace App\Filament\Resources;

use App\Enums\ComponentType;
use App\Filament\Resources\PcBuildResource\Pages;
use App\Models\PcBuild;
use App\Models\Product;
use App\Providers\Filament\AdminPanelProvider;
use App\Services\Helpers\CurrencyHelper;
use Filament\Forms;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PcBuildResource extends Resource
{
    protected static ?string $model = PcBuild::class;

    protected static ?string $navigationIcon = 'heroicon-o-computer-desktop';

    protected static ?string $navigationLabel = 'PC Builds';

    protected static ?string $modelLabel = 'PC build';

    protected static ?string $pluralModelLabel = 'PC builds';

    protected static ?int $navigationSort = 0;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Build budget')->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('Budget gaming PC'),

                TextInput::make('target_total')
                    ->label('Alert when total is at or below')
                    ->numeric()
                    ->minValue(0.01)
                    ->suffix(CurrencyHelper::getSymbol())
                    ->helperText('The total uses the cheapest available price for each selected product.'),
            ])->columns(2),

            Forms\Components\Section::make('Components')->schema([
                Repeater::make('items')
                    ->relationship()
                    ->schema([
                        Select::make('product_id')
                            ->label('Tracked component')
                            ->options(fn (): array => self::componentOptions())
                            ->searchable()
                            ->preload()
                            ->required()
                            ->disableOptionsWhenSelectedInSiblingRepeater(),

                        TextInput::make('quantity')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(10)
                            ->default(1)
                            ->required(),
                    ])
                    ->columns(4)
                    ->columnSpanFull()
                    ->defaultItems(1)
                    ->minItems(1)
                    ->addActionLabel('Add component')
                    ->reorderable(false),
            ])
                ->description('Only products assigned to CPU, GPU, RAM, SSD, PSU, or Other appear here.'),

            Forms\Components\Section::make('What the total includes')
                ->schema([
                    Forms\Components\Placeholder::make('total_note')
                        ->hiddenLabel()
                        ->content('Listed item prices only. Shipping, tax, import fees, and currency conversion are not included. Keep every product in a build in the same currency.'),
                ])
                ->collapsible()
                ->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::Bold),

                TextColumn::make('component_count')
                    ->label('Parts')
                    ->badge()
                    ->alignCenter(),

                TextColumn::make('current_total')
                    ->label('Cheapest total')
                    ->formatStateUsing(fn ($state): string => CurrencyHelper::toString($state))
                    ->color(fn (PcBuild $record): string => $record->target_total && $record->missing_price_count === 0 && $record->current_total <= $record->target_total
                        ? 'success'
                        : 'primary'),

                TextColumn::make('target_total')
                    ->label('Alert target')
                    ->placeholder('Not set')
                    ->formatStateUsing(fn ($state): string => filled($state) ? CurrencyHelper::toString($state) : 'Not set'),

                TextColumn::make('missing_price_count')
                    ->label('Unavailable')
                    ->badge()
                    ->color(fn ($state): string => (int) $state > 0 ? 'warning' : 'success')
                    ->formatStateUsing(fn ($state): string => (int) $state > 0 ? $state.' missing' : 'Complete'),

                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->paginated(AdminPanelProvider::DEFAULT_PAGINATION)
            ->defaultSort('updated_at', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Compare a complete PC build')
            ->emptyStateDescription('Group tracked CPU, GPU, RAM, SSD, and power-supply listings to see their cheapest combined price.')
            ->emptyStateIcon('heroicon-o-computer-desktop')
            ->emptyStateActions([
                Tables\Actions\CreateAction::make()->label('Create PC build'),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->currentUser()
            ->with(['items.product']);
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->where('user_id', auth()->id());
    }

    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        return static::getUrl('edit', ['record' => $record]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPcBuilds::route('/'),
            'create' => Pages\CreatePcBuild::route('/create'),
            'edit' => Pages\EditPcBuild::route('/{record}/edit'),
        ];
    }

    private static function componentOptions(): array
    {
        return Product::query()
            ->currentUser()
            ->whereNotNull('component_type')
            ->orderBy('component_type')
            ->orderBy('title')
            ->get()
            ->mapWithKeys(function (Product $product): array {
                $type = $product->component_type instanceof ComponentType
                    ? $product->component_type->getLabel()
                    : strtoupper((string) $product->component_type);
                $price = $product->current_price > 0
                    ? CurrencyHelper::toString($product->current_price)
                    : 'no current price';

                return [$product->getKey() => "[$type] {$product->title} — $price"];
            })
            ->all();
    }
}

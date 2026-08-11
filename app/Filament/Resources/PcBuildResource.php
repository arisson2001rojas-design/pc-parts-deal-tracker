<?php

namespace App\Filament\Resources;

use App\Enums\ComponentType;
use App\Filament\Resources\PcBuildResource\Pages;
use App\Models\PcBuild;
use App\Models\PcPart;
use App\Providers\Filament\AdminPanelProvider;
use App\Services\Helpers\CurrencyHelper;
use Filament\Forms;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class PcBuildResource extends Resource
{
    protected static ?string $model = PcBuild::class;

    protected static ?string $navigationIcon = 'heroicon-o-computer-desktop';

    protected static ?string $navigationLabel = 'Armados de PC';

    protected static ?string $modelLabel = 'armado de PC';

    protected static ?string $pluralModelLabel = 'armados de PC';

    protected static ?int $navigationSort = 0;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Presupuesto del armado')->schema([
                TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('PC gaming económica'),

                TextInput::make('target_total')
                    ->label('Avisar cuando el total sea igual o menor')
                    ->numeric()
                    ->minValue(0.01)
                    ->suffix(CurrencyHelper::getSymbol())
                    ->helperText('El total usa el precio disponible más barato de cada componente seleccionado.'),
            ])->columns(2),

            Forms\Components\Section::make('Componentes')->schema([
                Repeater::make('items')
                    ->label('Piezas del armado')
                    ->relationship()
                    ->schema([
                        Select::make('component_type')
                            ->label('Tipo')
                            ->options(collect(ComponentType::cases())->mapWithKeys(
                                fn (ComponentType $type): array => [$type->value => $type->getLabel()]
                            )->all())
                            ->placeholder('Selecciona el tipo')
                            ->native(false)
                            ->required()
                            ->live()
                            ->dehydrated(false)
                            ->afterStateHydrated(function (Select $component, mixed $state, Get $get): void {
                                if (filled($state) || blank($get('pc_part_id'))) {
                                    return;
                                }

                                $component->state(PcPart::query()->find($get('pc_part_id'))?->component_type?->value);
                            })
                            ->afterStateUpdated(fn (Set $set): mixed => $set('pc_part_id', null))
                            ->columnSpan(['default' => 12, 'md' => 3]),

                        Select::make('pc_part_id')
                            ->label('Componente del catálogo')
                            ->options(fn (Get $get): array => self::catalogOptions(
                                ComponentType::tryFrom((string) $get('component_type'))
                            ))
                            ->getSearchResultsUsing(fn (string $search, Get $get): array => self::catalogOptions(
                                ComponentType::tryFrom((string) $get('component_type')),
                                $search,
                            ))
                            ->getOptionLabelUsing(fn ($value): ?string => PcPart::query()->find($value)?->display_name)
                            ->searchable()
                            ->searchPrompt('Busca por marca, modelo, serie o capacidad…')
                            ->loadingMessage('Buscando en el catálogo…')
                            ->noSearchResultsMessage('No encontramos un componente de ese tipo. Prueba un modelo más corto.')
                            ->helperText('Solo aparecen piezas del tipo seleccionado.')
                            ->searchDebounce(350)
                            ->optionsLimit(50)
                            ->native(false)
                            ->live()
                            ->disabled(fn (Get $get): bool => blank($get('component_type')))
                            ->required()
                            ->distinct()
                            ->rules(fn (Get $get): array => [
                                Rule::exists('pc_parts', 'id')->where(
                                    fn ($query) => $query->where('component_type', $get('component_type'))
                                ),
                            ])
                            ->columnSpan(['default' => 12, 'md' => 7]),

                        TextInput::make('quantity')
                            ->label('Cantidad')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(10)
                            ->default(1)
                            ->required()
                            ->columnSpan(['default' => 12, 'md' => 2]),
                    ])
                    ->columns(12)
                    ->columnSpanFull()
                    ->defaultItems(1)
                    ->minItems(1)
                    ->itemLabel(fn (array $state): ?string => filled($state['pc_part_id'] ?? null)
                        ? PcPart::query()->find($state['pc_part_id'])?->display_name
                        : 'Nuevo componente')
                    ->addActionLabel('Añadir otro componente')
                    ->reorderable(false),
            ])
                ->description('Elige primero el tipo y luego una pieza compatible del catálogo. Al guardarla, PriceBuddy inicia el monitoreo aproximadamente cada 8 horas.'),

            Forms\Components\Section::make('Qué incluye el total')
                ->schema([
                    Forms\Components\Placeholder::make('total_note')
                        ->hiddenLabel()
                        ->content('Solo incluye los precios listados. No incluye envío, impuestos, importación ni conversión de moneda. Mantén todos los productos del armado en la misma moneda.'),
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
                    ->label('Piezas')
                    ->badge()
                    ->alignCenter(),

                TextColumn::make('current_total')
                    ->label('Total más barato')
                    ->formatStateUsing(fn ($state): string => CurrencyHelper::toString($state))
                    ->color(fn (PcBuild $record): string => $record->target_total && $record->missing_price_count === 0 && $record->current_total <= $record->target_total
                        ? 'success'
                        : 'primary'),

                TextColumn::make('target_total')
                    ->label('Objetivo')
                    ->placeholder('Sin definir')
                    ->formatStateUsing(fn ($state): string => filled($state) ? CurrencyHelper::toString($state) : 'Sin definir'),

                TextColumn::make('missing_price_count')
                    ->label('Disponibilidad')
                    ->badge()
                    ->color(fn ($state): string => (int) $state > 0 ? 'warning' : 'success')
                    ->formatStateUsing(fn ($state): string => (int) $state > 0 ? $state.' sin precio' : 'Completo'),

                TextColumn::make('updated_at')
                    ->label('Actualizado')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->paginated(AdminPanelProvider::DEFAULT_PAGINATION)
            ->defaultSort('updated_at', 'desc')
            ->actions([
                Tables\Actions\EditAction::make()->label('Editar'),
                Tables\Actions\DeleteAction::make()->label('Eliminar'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Compara un armado completo')
            ->emptyStateDescription('Agrupa CPU, GPU, motherboard, RAM, almacenamiento, cooler, gabinete y fuente para ver el total combinado más barato.')
            ->emptyStateIcon('heroicon-o-computer-desktop')
            ->emptyStateActions([
                Tables\Actions\CreateAction::make()->label('Crear armado de PC'),
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

    private static function catalogOptions(?ComponentType $componentType, string $search = ''): array
    {
        return PcPart::query()
            ->when($componentType, fn (Builder $query): Builder => $query->where('component_type', $componentType->value))
            ->when($search !== '', fn (Builder $query) => $query->searchCatalog($search))
            ->orderBy('component_type')
            ->orderByDesc('release_year')
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->groupBy(fn (PcPart $product): string => $product->component_type->getLabel())
            ->map(function (Collection $products): array {
                return $products->mapWithKeys(function (PcPart $product): array {
                    $maker = $product->manufacturer ? $product->manufacturer.' · ' : '';
                    $year = $product->release_year ? ' · '.$product->release_year : '';
                    $stores = collect(array_keys($product->retailer_urls ?? []))
                        ->map(fn (string $store): string => ucfirst($store))
                        ->join(', ');
                    $availability = $stores !== '' ? ' · '.$stores : ' · sin tienda identificada';

                    return [$product->getKey() => $maker.$product->title.$year.$availability];
                })->all();
            })
            ->all();
    }
}

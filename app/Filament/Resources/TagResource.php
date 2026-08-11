<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TagResource\Pages;
use App\Models\Tag;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TagResource extends Resource
{
    public const string API_GROUP = 'Tag';

    protected static ?string $model = Tag::class;

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationLabel = 'Etiquetas';

    protected static ?string $modelLabel = 'etiqueta';

    protected static ?string $pluralModelLabel = 'etiquetas';

    public static function getWeightHelperText(): string
    {
        return 'Los valores menores aparecen primero y ordenan los grupos de productos en Inicio.';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Etiqueta')
                    ->description('Sirve para agrupar productos en Inicio')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nombre')
                            ->required(),
                        Forms\Components\TextInput::make('weight')
                            ->label('Orden')
                            ->numeric()
                            ->default(0)
                            ->helperText(self::getWeightHelperText()),
                    ]),

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\Layout\Split::make([
                    Tables\Columns\TextColumn::make('name')
                        ->weight(FontWeight::Bold)
                        ->searchable()
                        ->sortable(),
                    Tables\Columns\TextColumn::make('products_count')
                        ->label(__('Products'))
                        ->formatStateUsing(fn ($state) => $state.' '.((int) $state === 1 ? 'producto' : 'productos'))
                        ->color('gray')
                        ->sortable(),
                    Tables\Columns\TextColumn::make('weight')
                        ->label('Orden')
                        ->color('gray')
                        ->sortable(),
                ])->from('md'),
            ])
            ->defaultSort('weight')
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Editar'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->modifyQueryUsing(function (Builder $query) {
                $query->currentUser()->withCount(['products']);
            })
            ->emptyStateHeading('Aún no tienes etiquetas')
            ->emptyStateDescription('Crea una etiqueta para ordenar y agrupar tus productos en Inicio.')
            ->emptyStateIcon('heroicon-o-tag')
            ->emptyStateActions([
                Tables\Actions\CreateAction::make()->label('Añadir etiqueta'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTags::route('/'),
            'create' => Pages\CreateTag::route('/create'),
            'edit' => Pages\EditTag::route('/{record}/edit'),
        ];
    }
}

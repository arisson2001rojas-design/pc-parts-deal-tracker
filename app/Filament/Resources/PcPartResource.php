<?php

namespace App\Filament\Resources;

use App\Enums\ComponentType;
use App\Filament\Resources\PcPartResource\Columns\PcPartCardColumn;
use App\Filament\Resources\PcPartResource\Pages;
use App\Jobs\UpdateProductPricesJob;
use App\Models\PcPart;
use App\Models\Product;
use App\Providers\Filament\AdminPanelProvider;
use App\Services\CatalogTrackingService;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class PcPartResource extends Resource
{
    protected static ?string $model = PcPart::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationLabel = 'Cheapest today';

    protected static ?string $modelLabel = 'catalog component';

    protected static ?string $pluralModelLabel = 'PC parts catalog';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(Table $table): Table
    {
        return $table
            ->heading('Cheapest monitored PC parts today')
            ->description('The catalog comes from BuildCores OpenDB (ODC-By 1.0). Numeric prices only appear after a trusted check; Newegg discoveries use curated feeds because direct automated access is not permitted.')
            ->columns([
                PcPartCardColumn::make('name')
                    ->label('Component')
                    ->searchable(['name', 'manufacturer', 'series', 'variant'])
                    ->sortable(),
            ])
            ->contentGrid([
                'md' => 2,
                'xl' => 3,
            ])
            ->filters([
                SelectFilter::make('component_type')
                    ->label('Component type')
                    ->options(collect(ComponentType::cases())->mapWithKeys(
                        fn (ComponentType $type): array => [$type->value => $type->getLabel()]
                    )->all()),
            ])
            ->defaultSort('current_price')
            ->paginated(AdminPanelProvider::DEFAULT_PAGINATION)
            ->actions([
                Tables\Actions\Action::make('track')
                    ->label('Track prices')
                    ->icon('heroicon-o-bell')
                    ->visible(fn (PcPart $record): bool => $record->currentUserProduct === null)
                    ->action(function (PcPart $record, CatalogTrackingService $tracking): void {
                        $tracking->track($record, auth()->id());
                        Notification::make()
                            ->title('Price check queued')
                            ->body('Approved retailer checks were queued. Newegg links are discovered through curated deal feeds.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('refresh')
                    ->label('Check now')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (PcPart $record): bool => $record->currentUserProduct !== null)
                    ->action(function (PcPart $record): void {
                        if ($product = $record->currentUserProduct) {
                            UpdateProductPricesJob::dispatch($product, true);
                        }

                        Notification::make()->title('Price check queued')->success()->send();
                    }),

                Tables\Actions\Action::make('view_product')
                    ->label('Price history')
                    ->icon('heroicon-o-chart-bar')
                    ->visible(fn (PcPart $record): bool => $record->currentUserProduct !== null)
                    ->url(fn (PcPart $record): string => ProductResource::getUrl('view', [
                        'record' => $record->currentUserProduct,
                    ])),

                Tables\Actions\Action::make('buy')
                    ->label('Open store')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->visible(fn (PcPart $record): bool => filled(data_get($record->currentUserProduct?->price_cache, '0.url')))
                    ->url(fn (PcPart $record): ?string => data_get($record->currentUserProduct?->price_cache, '0.url'))
                    ->openUrlInNewTab(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('track')
                    ->label('Track selected prices')
                    ->icon('heroicon-o-bell')
                    ->action(function (Collection $records, CatalogTrackingService $tracking): void {
                        $limit = max(1, (int) config('price_buddy.pc_parts_bulk_track_limit', 25));
                        if ($records->count() > $limit) {
                            Notification::make()
                                ->title('Too many parts selected')
                                ->body("Select at most {$limit} components at a time so retailer checks stay controlled.")
                                ->danger()
                                ->send();

                            return;
                        }

                        $records->each(function ($part) use ($tracking): void {
                            if ($part instanceof PcPart) {
                                $tracking->track($part, auth()->id());
                            }
                        });
                        Notification::make()->title('Price checks queued')->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->emptyStateHeading('No monitored prices for today yet')
            ->emptyStateDescription('Open the All catalog tab, choose components, and select Track prices. Checks continue automatically about every 8 hours.')
            ->emptyStateIcon('heroicon-o-magnifying-glass');
    }

    public static function getEloquentQuery(): Builder
    {
        $userId = auth()->id();

        return parent::getEloquentQuery()
            ->with('currentUserProduct')
            ->addSelect([
                'current_price' => Product::query()
                    ->select('current_price')
                    ->whereColumn('pc_part_id', 'pc_parts.id')
                    ->where('user_id', $userId)
                    ->where('paused', false)
                    ->limit(1),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPcParts::route('/'),
        ];
    }
}

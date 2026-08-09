<?php

namespace App\Filament\Resources\DealOfferResource\Pages;

use App\Enums\ComponentType;
use App\Filament\Resources\DealOfferResource;
use App\Jobs\RefreshDealSearchJob;
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
            'recent' => Tab::make('Cheapest (7 days)')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereNotNull('price')
                    ->where('fetched_at', '>=', now()->subDays(7))
                ),
            'today' => Tab::make('Best today')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereNotNull('price')
                    ->whereDate('fetched_at', today())
                ),
            'target' => Tab::make('Under target')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereNotNull('price')
                    ->whereHas('dealSearch', fn (Builder $search): Builder => $search
                        ->whereNotNull('target_price')
                        ->whereColumn('deal_offers.price', '<=', 'deal_searches.target_price')
                    )
                ),
            'all' => Tab::make('All discoveries'),
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
                ->label('Hunt a component')
                ->icon('heroicon-o-plus')
                ->form([
                    Forms\Components\TextInput::make('name')->required()->maxLength(255),
                    Forms\Components\TextInput::make('query')
                        ->label('Exact component')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\Select::make('component_type')
                        ->label('Type')
                        ->options(collect(ComponentType::cases())->mapWithKeys(
                            fn (ComponentType $type): array => [$type->value => $type->getLabel()]
                        )->all())
                        ->required(),
                    Forms\Components\TextInput::make('target_price')
                        ->label('Alert price')
                        ->prefix('$')
                        ->numeric(),
                ])
                ->action(function (array $data): void {
                    $search = DealSearch::query()->create($data + [
                        'user_id' => auth()->id(),
                        'enabled' => true,
                    ]);
                    RefreshDealSearchJob::dispatch($search->getKey());
                    Notification::make()->title('Hunt started')->success()->send();
                }),
            Actions\Action::make('refresh')
                ->label('Search all stores now')
                ->icon('heroicon-o-arrow-path')
                ->action(function (): void {
                    DealSearch::query()
                        ->currentUser()
                        ->where('enabled', true)
                        ->pluck('id')
                        ->each(fn (int $id) => RefreshDealSearchJob::dispatch($id));
                    Notification::make()->title('All deal searches queued')->success()->send();
                }),
        ];
    }
}

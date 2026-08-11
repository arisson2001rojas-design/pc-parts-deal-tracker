<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Enums\Icons;
use App\Filament\Actions\BaseAction;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Widgets\UrlsTableWidget;
use App\Models\Product;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ProductResource\Actions\AddUrlAction::make(),
            BaseAction::make('view')->icon(Icons::View->value)
                ->label(__('View'))
                ->resourceName('product')
                ->resourceUrl('view', $this->record),
            Actions\DeleteAction::make()->icon(Icons::Delete->value),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Product $product */
        $product = $this->record;

        if (array_key_exists('paused', $data) && (bool) $data['paused'] !== $product->paused) {
            $product->setUserPaused((bool) $data['paused']);
            unset($data['paused']);
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    protected function getFooterWidgets(): array
    {
        return [
            UrlsTableWidget::class,
        ];
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 1;
    }

    protected function afterSave(): void
    {
        /** @var Product $product */
        $product = Product::find($this->record->getKey());

        $product->tags()->sync($this->data['tags']);
        $product->updatePriceCache();
    }
}

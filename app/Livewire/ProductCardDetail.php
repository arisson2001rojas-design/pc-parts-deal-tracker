<?php

namespace App\Livewire;

use App\Filament\Resources\ProductResource\Actions\AddUrlAction;
use App\Jobs\UpdateAllPricesJob;
use App\Models\Product;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Livewire\Component;

class ProductCardDetail extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    public Product $product;

    public bool $standalone = false;

    public bool $showChart = false;

    public bool $showNextCheck = true;

    public function getRecord(): Product
    {
        return $this->product;
    }

    public function mount(Product $product, bool $standalone = false, bool $showChart = false, bool $showNextCheck = true): void
    {
        $this->product = $product;
        $this->standalone = $standalone;
        $this->showChart = $showChart;
        $this->showNextCheck = $showNextCheck;
    }

    public function addUrlAction(): Action
    {
        return AddUrlAction::make('addUrl')
            ->label('Añadir URL')
            ->record($this->product)
            ->size('sm');
    }

    public function fetchAction(): Action
    {
        return Action::make('fetch')
            ->label('Actualizar')
            ->size('sm')
            ->color('gray')
            ->icon('heroicon-o-rocket-launch')
            ->outlined(false)
            ->action(function () {
                try {
                    UpdateAllPricesJob::dispatch([$this->product->id]);

                    Notification::make('fetched_prices')
                        ->title('Precios actualizándose en segundo plano')
                        ->success()
                        ->send();

                    $this->dispatch('$refresh');
                } catch (Exception $e) {
                    Notification::make('fetch_failed')
                        ->title('No se pudo actualizar el producto; revisa los registros')
                        ->danger()
                        ->send();
                }
            });
    }

    public function editAction(): Action
    {
        return Action::make('edit')
            ->label('Editar')
            ->size('sm')
            ->color('gray')
            ->icon('heroicon-o-pencil')
            ->outlined(false)
            ->url(fn () => route('filament.admin.resources.products.edit', ['record' => $this->product->id]));
    }

    public function deleteAction(): Action
    {
        return Action::make('delete')
            ->label('Eliminar')
            ->size('sm')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->outlined(false)
            ->requiresConfirmation(true)
            ->hidden(fn () => auth()->user()->cannot('delete', $this->product))
            ->authorize('delete', $this->product)
            ->action(function () {
                $this->product->delete();

                Notification::make('deleted_product')
                    ->title('Producto eliminado')
                    ->success()
                    ->send();

                return redirect('/admin');
            });
    }

    public function render()
    {
        return view('components.livewire.product-card-detail');
    }
}

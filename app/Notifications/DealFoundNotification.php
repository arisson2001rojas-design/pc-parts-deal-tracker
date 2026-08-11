<?php

namespace App\Notifications;

use App\Models\DealOffer;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as DatabaseNotification;
use Illuminate\Notifications\Notification;

class DealFoundNotification extends Notification
{
    public function __construct(private readonly DealOffer $offer) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return DatabaseNotification::make()
            ->title('Oferta encontrada: $'.number_format((float) $this->offer->price, 2))
            ->body($this->offer->store.' · '.$this->offer->title)
            ->status('success')
            ->actions([
                Action::make('buy')
                    ->url($this->offer->url, true)
                    ->label('Ver en la tienda'),
            ])
            ->getDatabaseMessage();
    }
}

<?php

namespace App\Notifications;

use App\Filament\Resources\PcBuildResource;
use App\Models\PcBuild;
use App\Models\User;
use App\Notifications\Messages\GenericNotificationMessage;
use App\Services\Helpers\CurrencyHelper;
use App\Services\Helpers\NotificationsHelper;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as DatabaseNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Pushover\PushoverMessage;

class PcBuildTargetNotification extends Notification
{
    public function __construct(protected PcBuild $build) {}

    public function via(User $notifiable): array
    {
        return NotificationsHelper::getEnabledChannels($notifiable)
            ->push('database')
            ->toArray();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->getTitle())
            ->line($this->getSummary())
            ->action('View PC build', $this->getUrl());
    }

    public function toDatabase($notifiable): array
    {
        return DatabaseNotification::make()
            ->title($this->getTitle())
            ->body($this->getSummary())
            ->status('success')
            ->actions([
                Action::make('view')
                    ->url(parse_url($this->getUrl(), PHP_URL_PATH), false)
                    ->label('View build'),
            ])
            ->getDatabaseMessage();
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->getTitle(),
            'summary' => $this->getSummary(),
            'buildName' => $this->build->name,
            'buildUrl' => $this->getUrl(),
            'currentTotal' => CurrencyHelper::toString($this->build->current_total),
            'targetTotal' => CurrencyHelper::toString($this->build->target_total),
        ];
    }

    public function toPushover($notifiable): PushoverMessage
    {
        return PushoverMessage::create($this->getSummary())
            ->title($this->getTitle())
            ->url($this->getUrl(), 'View PC build');
    }

    public function toGotify($notifiable): GenericNotificationMessage
    {
        return $this->genericMessage();
    }

    public function toApprise($notifiable): GenericNotificationMessage
    {
        return $this->genericMessage();
    }

    public function toTelegram($notifiable): GenericNotificationMessage
    {
        return $this->genericMessage();
    }

    public function toDiscord($notifiable): GenericNotificationMessage
    {
        return $this->genericMessage();
    }

    public function toNtfy($notifiable): GenericNotificationMessage
    {
        return $this->genericMessage();
    }

    protected function genericMessage(): GenericNotificationMessage
    {
        return GenericNotificationMessage::create($this->getSummary())
            ->title($this->getTitle())
            ->url($this->getUrl())
            ->priority(5);
    }

    protected function getTitle(): string
    {
        return 'PC build reached target: '.$this->build->name;
    }

    protected function getSummary(): string
    {
        return 'The cheapest available total is '.CurrencyHelper::toString($this->build->current_total)
            .', at or below your target of '.CurrencyHelper::toString($this->build->target_total).'.';
    }

    protected function getUrl(): string
    {
        return PcBuildResource::getUrl('edit', ['record' => $this->build]);
    }
}

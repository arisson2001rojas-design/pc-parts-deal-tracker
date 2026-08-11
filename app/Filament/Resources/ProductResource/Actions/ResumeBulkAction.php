<?php

namespace App\Filament\Resources\ProductResource\Actions;

use App\Models\Product;
use Filament\Actions\Concerns\CanCustomizeProcess;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class ResumeBulkAction extends BulkAction
{
    use CanCustomizeProcess;

    public static function getDefaultName(): ?string
    {
        return 'resume';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Resume checking'));

        $this->successNotificationTitle(__('Products resumed'));

        $this->color('gray');

        $this->icon('heroicon-o-play');

        $this->action(function (): void {
            $this->process(static function (Collection $records) {
                $records->each(static function (Model $record): void {
                    if ($record instanceof Product) {
                        $record->setUserPaused(false)->save();
                    }
                });
            });

            $this->success();
        });

        $this->deselectRecordsAfterCompletion();
    }
}

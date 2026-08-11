<?php

namespace App\Jobs;

use App\Models\DealSearch;
use App\Services\DealHunterService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshDealSearchJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 1800;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public readonly int $dealSearchId) {}

    public function handle(DealHunterService $hunter): void
    {
        $search = DealSearch::query()->find($this->dealSearchId);
        if ($search?->enabled) {
            $hunter->refresh($search);
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->dealSearchId;
    }
}

<?php

namespace App\Console\Commands;

use App\Jobs\RefreshDealSearchJob;
use App\Models\DealSearch;
use Illuminate\Console\Command;

class RefreshDealSearches extends Command
{
    public const COMMAND = 'deal-hunter:refresh';

    protected $signature = self::COMMAND.' {--user= : Only this user email} {--stale : Skip searches refreshed recently}';

    protected $description = 'Queue web deal discovery for saved PC component searches';

    public function handle(): int
    {
        $cutoff = now()->subHours((int) config('deal_hunter.refresh_hours', 8));

        $query = DealSearch::query()
            ->where('enabled', true)
            ->when($this->option('user'), fn ($builder, $email) => $builder
                ->whereHas('user', fn ($userQuery) => $userQuery->where('email', $email))
            )
            ->when($this->option('stale'), fn ($builder) => $builder
                ->where(fn ($staleQuery) => $staleQuery
                    ->whereNull('last_searched_at')
                    ->orWhere('last_searched_at', '<=', $cutoff)
                )
            );

        $queued = 0;
        $query->select('id')->orderBy('id')->each(function (DealSearch $search) use (&$queued): void {
            RefreshDealSearchJob::dispatch($search->getKey());
            $queued++;
        });

        $this->components->info("Queued {$queued} deal searches.");

        return self::SUCCESS;
    }
}

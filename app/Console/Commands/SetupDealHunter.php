<?php

namespace App\Console\Commands;

use App\Models\DealSearch;
use App\Models\User;
use Illuminate\Console\Command;

class SetupDealHunter extends Command
{
    public const COMMAND = 'deal-hunter:setup';

    protected $signature = self::COMMAND.' {--user= : User email}';

    protected $description = 'Create editable starter searches for the PC deal hunter';

    public function handle(): int
    {
        $user = User::query()
            ->when($this->option('user'), fn ($query, $email) => $query->where('email', $email))
            ->oldest()
            ->first();

        if (! $user) {
            $this->components->error('No user was found.');

            return self::FAILURE;
        }

        $created = 0;
        foreach ((array) config('deal_hunter.starter_searches', []) as $search) {
            $record = DealSearch::query()->firstOrCreate(
                ['user_id' => $user->getKey(), 'query' => $search['query']],
                $search + ['enabled' => true]
            );
            $created += (int) $record->wasRecentlyCreated;
        }

        $this->components->info("Created {$created} starter searches for {$user->email}.");

        return self::SUCCESS;
    }
}

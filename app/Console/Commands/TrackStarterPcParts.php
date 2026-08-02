<?php

namespace App\Console\Commands;

use App\Enums\ComponentType;
use App\Models\PcPart;
use App\Models\User;
use App\Services\CatalogTrackingService;
use Illuminate\Console\Command;

class TrackStarterPcParts extends Command
{
    public const COMMAND = 'pc-parts:track-starters';

    protected $signature = self::COMMAND.' {--user= : User email} {--stores=amazon : Comma-separated retailers}';

    protected $description = 'Track a starter set of affordable PC parts from the open catalog';

    public function handle(CatalogTrackingService $tracking): int
    {
        $user = User::query()
            ->when($this->option('user'), fn ($query, $email) => $query->where('email', $email))
            ->oldest()
            ->first();

        if (! $user) {
            $this->components->error('No user was found for starter tracking.');

            return self::FAILURE;
        }

        $retailers = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('stores')))));
        $tracked = 0;
        $seen = [];

        foreach ((array) config('price_buddy.pc_parts_starter_searches', []) as $type => $searches) {
            $componentType = ComponentType::tryFrom((string) $type);
            if (! $componentType) {
                continue;
            }

            foreach ((array) $searches as $search) {
                $part = PcPart::query()
                    ->where('component_type', $componentType->value)
                    ->searchCatalog((string) $search)
                    ->orderByDesc('release_year')
                    ->limit(25)
                    ->get()
                    ->first(fn (PcPart $part): bool => $this->supportsRetailer($part, $retailers));

                if (! $part || isset($seen[$part->getKey()])) {
                    continue;
                }

                $tracking->track($part, $user->getKey(), $retailers);
                $seen[$part->getKey()] = true;
                $tracked++;
            }
        }

        $this->components->info("Queued initial price checks for {$tracked} catalog components.");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $retailers
     */
    private function supportsRetailer(PcPart $part, array $retailers): bool
    {
        foreach ($retailers as $retailer) {
            if (filled(data_get($part->retailer_urls, $retailer))) {
                return true;
            }
        }

        return false;
    }
}


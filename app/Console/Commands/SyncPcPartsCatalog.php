<?php

namespace App\Console\Commands;

use App\Services\BuildCoresCatalogImporter;
use Illuminate\Console\Command;
use Throwable;

class SyncPcPartsCatalog extends Command
{
    public const COMMAND = 'pc-parts:sync-catalog';

    protected $signature = self::COMMAND.' {--source= : Local ZIP path or catalog URL}';

    protected $description = 'Import the open BuildCores PC component catalog';

    public function handle(BuildCoresCatalogImporter $importer): int
    {
        $source = (string) ($this->option('source') ?: config('price_buddy.pc_parts_catalog_url'));

        try {
            $count = $importer->import($source);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Imported {$count} PC components from BuildCores OpenDB.");

        return self::SUCCESS;
    }
}


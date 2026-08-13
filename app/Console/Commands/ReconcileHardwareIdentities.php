<?php

namespace App\Console\Commands;

use App\Enums\ComponentType;
use App\Enums\IdentityResolutionState;
use App\Models\Product;
use App\Services\IdentityReconciliationService;
use Illuminate\Console\Command;

final class ReconcileHardwareIdentities extends Command
{
    public const string COMMAND = 'pc:identity:reconcile';

    protected $signature = self::COMMAND.'
        {--dry-run : Explicitly document that this command is non-destructive}
        {--product=* : Inspect only these Product IDs}
        {--limit=100 : Maximum number of Products to inspect}
        {--component-type= : Restrict to one PC component type}
        {--only= : Show only one resolution state}';

    protected $description = 'Dry-run retailer listing and hardware identity reconciliation; never mutates data.';

    public function handle(IdentityReconciliationService $reconciler): int
    {
        $limit = max(1, min(10000, (int) $this->option('limit')));
        $componentType = $this->option('component-type');
        if (filled($componentType) && ComponentType::tryFrom((string) $componentType) === null) {
            $this->components->error('Unknown component type.');

            return self::INVALID;
        }
        $only = $this->option('only');
        if (filled($only) && IdentityResolutionState::tryFrom((string) $only) === null) {
            $this->components->error('Unknown resolution state.');

            return self::INVALID;
        }

        $productIds = array_values(array_filter(array_map('intval', (array) $this->option('product'))));
        $query = Product::query()->with(['pcPart.hardwareIdentity', 'urls.listing'])->orderBy('id');
        if ($productIds !== []) {
            $query->whereKey($productIds);
        }
        if (filled($componentType)) {
            $query->where('component_type', (string) $componentType);
        }

        $products = $query->limit($limit)->get();
        $reported = 0;
        foreach ($products as $product) {
            foreach ($reconciler->inspectProduct($product) as $row) {
                if (filled($only) && ($row['resolution']['state'] ?? null) !== $only) {
                    continue;
                }
                $this->line(json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                $reported++;
            }
        }

        $this->components->info(sprintf(
            'Dry-run complete: inspected %d Product(s), reported %d retailer listing(s), wrote 0 records.',
            $products->count(),
            $reported,
        ));

        return self::SUCCESS;
    }
}

<?php

namespace App\Models;

use App\Enums\ComponentType;
use Database\Factories\PcPartFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $opendb_id
 * @property ComponentType $component_type
 * @property string $name
 * @property ?string $manufacturer
 * @property ?string $series
 * @property ?string $variant
 * @property ?int $release_year
 * @property array<string, string> $retailer_urls
 * @property array<string, mixed> $specifications
 * @property ?Product $currentUserProduct
 */
class PcPart extends Model
{
    /** @use HasFactory<PcPartFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'component_type' => ComponentType::class,
            'part_numbers' => 'array',
            'retailer_urls' => 'array',
            'specifications' => 'array',
            'release_year' => 'integer',
            'source_updated_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * @return HasOne<Product, $this>
     */
    public function currentUserProduct(): HasOne
    {
        return $this->hasOne(Product::class)
            ->where('user_id', auth()->id())
            ->where('paused', false);
    }

    public function scopeSearchCatalog(Builder $query, string $search): Builder
    {
        $search = trim($search);

        return $query->where(function (Builder $query) use ($search): void {
            $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('manufacturer', 'like', "%{$search}%")
                ->orWhere('series', 'like', "%{$search}%");
        });
    }

    public function getDisplayNameAttribute(): string
    {
        return '['.$this->component_type->getLabel().'] '.$this->name;
    }

    public function getTitleAttribute(): string
    {
        return $this->name;
    }
}

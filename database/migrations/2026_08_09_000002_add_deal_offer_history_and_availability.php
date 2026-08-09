<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_offers', function (Blueprint $table): void {
            $table->string('availability', 20)->default('unknown')->after('source');
            $table->string('seller')->nullable()->after('availability');
        });

        Schema::create('deal_offer_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deal_offer_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 10, 2);
            $table->char('currency', 3)->default('USD');
            $table->string('source', 40);
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->index(['deal_offer_id', 'captured_at']);
        });

        $now = now();
        DB::table('deal_offers')
            ->whereNotNull('price')
            ->whereIn('source', [
                'best_buy_api',
                'dealnews_rss',
                'direct_extract',
                'browser_capture',
            ])
            ->orderBy('id')
            ->chunkById(200, function ($offers) use ($now): void {
                DB::table('deal_offer_prices')->insert($offers->map(fn ($offer): array => [
                    'deal_offer_id' => $offer->id,
                    'price' => $offer->price,
                    'currency' => $offer->currency,
                    'source' => $offer->source,
                    'captured_at' => $offer->fetched_at,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_offer_prices');

        Schema::table('deal_offers', function (Blueprint $table): void {
            $table->dropColumn(['availability', 'seller']);
        });
    }
};

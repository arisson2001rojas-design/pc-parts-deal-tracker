<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_offers', function (Blueprint $table): void {
            $table->string('seller_type', 20)->nullable();
            $table->string('condition', 20)->nullable();
            $table->string('offer_scope', 20)->nullable();
            $table->string('purchasability', 30)->nullable();
            $table->string('fulfillment_type', 20)->nullable();
            $table->string('evidence_quality', 20)->nullable();
            $table->boolean('bundle')->nullable();
            $table->boolean('comparison_eligible')->nullable();
            $table->json('offer_evidence')->nullable();
        });

        Schema::table('deal_offer_prices', function (Blueprint $table): void {
            $table->string('seller')->nullable();
            $table->string('availability', 20)->nullable();
            $table->string('seller_type', 20)->nullable();
            $table->string('condition', 20)->nullable();
            $table->string('offer_scope', 20)->nullable();
            $table->string('purchasability', 30)->nullable();
            $table->string('fulfillment_type', 20)->nullable();
            $table->string('evidence_quality', 20)->nullable();
            $table->boolean('bundle')->nullable();
            $table->boolean('comparison_eligible')->nullable();
            $table->json('offer_evidence')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deal_offer_prices', function (Blueprint $table): void {
            $table->dropColumn([
                'seller',
                'availability',
                'seller_type',
                'condition',
                'offer_scope',
                'purchasability',
                'fulfillment_type',
                'evidence_quality',
                'bundle',
                'comparison_eligible',
                'offer_evidence',
            ]);
        });

        Schema::table('deal_offers', function (Blueprint $table): void {
            $table->dropColumn([
                'seller_type',
                'condition',
                'offer_scope',
                'purchasability',
                'fulfillment_type',
                'evidence_quality',
                'bundle',
                'comparison_eligible',
                'offer_evidence',
            ]);
        });
    }
};

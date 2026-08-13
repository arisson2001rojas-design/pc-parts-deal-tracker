<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hardware_identities', function (Blueprint $table): void {
            $table->id();
            $table->string('component_type', 20)->index();
            $table->string('manufacturer')->nullable();
            $table->string('manufacturer_normalized')->nullable()->index();
            $table->string('model')->nullable();
            $table->string('model_normalized')->nullable()->index();
            $table->string('mpn')->nullable();
            $table->string('mpn_normalized')->nullable()->index();
            $table->char('authoritative_key_hash', 64)->nullable()->unique();
            $table->char('variant_fingerprint', 64)->nullable()->index();
            $table->json('attributes')->nullable();
            $table->timestamps();
        });

        Schema::create('retailer_listings', function (Blueprint $table): void {
            $table->id();
            $table->string('retailer_key', 80)->index();
            $table->string('identifier_type', 40);
            $table->string('external_identifier');
            $table->char('listing_key_hash', 64)->unique();
            $table->text('canonical_url');
            $table->string('normalized_url', 255)->nullable()->index();
            $table->char('url_hash', 64)->nullable()->index();
            $table->string('title', 1024)->nullable();
            $table->string('seller')->nullable();
            $table->boolean('marketplace')->nullable();
            $table->foreignId('hardware_identity_id')
                ->nullable()
                ->constrained('hardware_identities')
                ->nullOnDelete();
            $table->string('resolution_state', 20)->default('unverified')->index();
            $table->text('resolution_reason')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->json('evidence')->nullable();
            $table->json('decision_trace')->nullable();
            $table->timestamps();
        });

        Schema::create('hardware_identity_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hardware_identity_id')
                ->constrained('hardware_identities')
                ->cascadeOnDelete();
            $table->string('claim_type', 40);
            $table->string('value');
            $table->string('normalized_value');
            // Only authoritative claims are persisted in this table. A given
            // manufacturer-scoped MPN may therefore belong to one physical
            // identity at most, including under concurrent ingestion.
            $table->char('claim_key_hash', 64)->unique();
            $table->string('source', 80)->nullable();
            $table->boolean('verified')->default(false);
            $table->json('evidence')->nullable();
            $table->timestamps();
        });

        Schema::table('urls', function (Blueprint $table): void {
            $table->foreignId('retailer_listing_id')
                ->nullable()
                ->after('store_id')
                ->constrained('retailer_listings')
                ->nullOnDelete();
        });

        Schema::table('deal_offers', function (Blueprint $table): void {
            $table->foreignId('retailer_listing_id')
                ->nullable()
                ->after('deal_search_id')
                ->constrained('retailer_listings')
                ->nullOnDelete();
        });

        Schema::table('pc_parts', function (Blueprint $table): void {
            $table->foreignId('hardware_identity_id')
                ->nullable()
                ->after('id')
                ->constrained('hardware_identities')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('urls', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('retailer_listing_id');
        });

        Schema::table('deal_offers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('retailer_listing_id');
        });

        Schema::table('pc_parts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('hardware_identity_id');
        });

        Schema::dropIfExists('retailer_listings');
        Schema::dropIfExists('hardware_identity_claims');
        Schema::dropIfExists('hardware_identities');
    }
};

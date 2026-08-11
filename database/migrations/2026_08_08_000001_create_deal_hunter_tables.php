<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_searches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('query');
            $table->string('component_type', 20);
            $table->decimal('target_price', 10, 2)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_searched_at')->nullable();
            $table->decimal('last_notified_price', 10, 2)->nullable();
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'query']);
            $table->index(['user_id', 'enabled']);
        });

        Schema::create('deal_offers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deal_search_id')->constrained()->cascadeOnDelete();
            $table->string('store', 40);
            $table->string('title', 1024);
            $table->text('url');
            $table->char('url_hash', 64);
            $table->decimal('price', 10, 2)->nullable();
            $table->char('currency', 3)->default('USD');
            $table->string('source', 40)->default('web_index');
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['deal_search_id', 'url_hash']);
            $table->index(['store', 'price']);
            $table->index('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_offers');
        Schema::dropIfExists('deal_searches');
    }
};

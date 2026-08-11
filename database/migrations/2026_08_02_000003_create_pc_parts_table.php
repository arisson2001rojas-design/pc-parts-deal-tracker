<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pc_parts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('opendb_id')->unique();
            $table->string('component_type', 20)->index();
            $table->string('name', 1024);
            $table->string('manufacturer')->nullable()->index();
            $table->string('series')->nullable();
            $table->string('variant')->nullable();
            $table->json('part_numbers')->nullable();
            $table->unsignedSmallInteger('release_year')->nullable()->index();
            $table->json('retailer_urls')->nullable();
            $table->json('specifications')->nullable();
            $table->string('source_url', 1024);
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pc_parts');
    }
};

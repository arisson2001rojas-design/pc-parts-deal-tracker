<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pc_build_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pc_build_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->timestamps();

            $table->unique(['pc_build_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pc_build_items');
    }
};

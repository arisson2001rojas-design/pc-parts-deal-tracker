<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('pc_part_id')
                ->nullable()
                ->after('user_id')
                ->constrained('pc_parts')
                ->nullOnDelete();
            $table->unique(['user_id', 'pc_part_id']);
        });

        Schema::table('pc_build_items', function (Blueprint $table): void {
            $table->dropUnique(['pc_build_id', 'product_id']);
            $table->dropForeign(['product_id']);
        });

        Schema::table('pc_build_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_id')->nullable()->change();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->foreignId('pc_part_id')
                ->nullable()
                ->after('product_id')
                ->constrained('pc_parts')
                ->nullOnDelete();
            $table->unique(['pc_build_id', 'pc_part_id']);
        });
    }

    public function down(): void
    {
        DB::table('pc_build_items')->whereNull('product_id')->delete();

        Schema::table('pc_build_items', function (Blueprint $table): void {
            $table->dropUnique(['pc_build_id', 'pc_part_id']);
            $table->dropConstrainedForeignId('pc_part_id');
            $table->dropForeign(['product_id']);
        });

        Schema::table('pc_build_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_id')->nullable(false)->change();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->unique(['pc_build_id', 'product_id']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'pc_part_id']);
            $table->dropConstrainedForeignId('pc_part_id');
        });
    }
};

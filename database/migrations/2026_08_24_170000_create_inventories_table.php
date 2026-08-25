<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity')->default(0);
            $table->timestamps();

            $table->unique(['warehouse_id', 'product_id']);
        });

        DB::statement('ALTER TABLE inventories ADD CONSTRAINT inventories_quantity_non_negative CHECK (quantity >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventories');
    }
};

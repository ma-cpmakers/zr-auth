<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // La persona come la conosce zr-home: l'id è il `sub` dell'id_token, il resto si ricopia a ogni ingresso.
        Schema::create('zr_persone', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('locale', 10)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zr_persone');
    }
};

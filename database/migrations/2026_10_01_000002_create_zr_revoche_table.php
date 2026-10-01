<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Le revoche chieste dagli avvisi di zr-home: l'id è l'ordine d'arrivo, e si tengono quanto vale una sessione.
        Schema::create('zr_revoche', function (Blueprint $table) {
            $table->id();
            $table->string('sid')->nullable();
            $table->unsignedBigInteger('sub')->nullable();
            $table->unsignedBigInteger('workspace')->nullable();
            $table->string('motivo', 40)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zr_revoche');
    }
};

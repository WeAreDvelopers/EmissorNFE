<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Empresa isenta de cobrança de créditos (definido só pelo master). Homologação já é sempre grátis.
        Schema::table('emitentes', function (Blueprint $table) {
            $table->boolean('cobranca_desligada')->default(false)->after('ativo');
        });
    }

    public function down(): void
    {
        Schema::table('emitentes', fn (Blueprint $table) => $table->dropColumn('cobranca_desligada'));
    }
};

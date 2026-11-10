<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Códigos complementares municipais (cTribMun) que a prefeitura da empresa definiu e que ela usa nas notas.
        // A lista é da prefeitura, então fica por empresa (cada empresa tem o seu município).
        Schema::create('emitente_codigos_municipais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conta_id')->constrained('contas');
            $table->foreignId('emitente_id')->constrained('emitentes')->cascadeOnDelete();
            $table->string('codigo', 3);
            $table->string('descricao', 500)->nullable();
            $table->string('codigo_servico', 6)->nullable();   // código de tributação nacional a que pertence (opcional)
            $table->timestamps();
            $table->unique(['emitente_id', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emitente_codigos_municipais');
    }
};

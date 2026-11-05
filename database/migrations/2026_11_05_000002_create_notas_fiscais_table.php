<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notas_fiscais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conta_id')->constrained('contas');
            $table->foreignId('emitente_id')->constrained('emitentes');
            // Nota pode vir da planilha, da tela ou do WhatsApp (fase 3) — por isso importação é opcional
            $table->foreignId('importacao_id')->nullable()->constrained('importacoes')->cascadeOnDelete();
            $table->string('origem')->default('planilha');               // planilha | web | whatsapp
            $table->unsignedInteger('linha_planilha')->nullable();

            // Status: ver App\Enums\StatusNota
            $table->string('status')->default('pendente')->index();
            $table->unsignedInteger('ciclo_credito')->default(0);       // +1 a cada reserva (reenvio)

            // Numeração da DPS: única por emitente + série (E0014)
            $table->string('serie_dps', 5);
            $table->unsignedBigInteger('numero_dps')->nullable();
            $table->unique(['emitente_id', 'serie_dps', 'numero_dps']);
            $table->index(['conta_id', 'status']);

            // Dados vindos da planilha
            $table->string('tomador_documento', 14);
            $table->string('tomador_nome', 300);
            $table->string('tomador_email')->nullable();
            $table->string('codigo_servico', 6);       // cTribNac (6 dígitos)
            $table->string('codigo_nbs', 9)->nullable();
            $table->text('descricao');
            $table->decimal('valor', 15, 2);
            $table->date('data_competencia');

            // Retorno da SEFIN
            $table->string('chave_acesso', 50)->nullable()->unique();
            $table->string('numero_nfse')->nullable();
            $table->longText('xml_dps')->nullable();
            $table->longText('xml_nfse')->nullable();
            $table->json('erros')->nullable();
            $table->unsignedSmallInteger('tentativas')->default(0);
            $table->timestamp('emitida_em')->nullable();

            $table->timestamps();
        });

        Schema::table('movimentos_credito', function (Blueprint $table) {
            $table->foreign('nota_fiscal_id')->references('id')->on('notas_fiscais');
        });
    }

    public function down(): void
    {
        Schema::table('movimentos_credito', fn (Blueprint $t) => $t->dropForeign(['nota_fiscal_id']));
        Schema::dropIfExists('notas_fiscais');
    }
};

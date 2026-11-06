<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cadastro de clientes (tomadores) de cada conta. Um CPF/CNPJ por conta.
        Schema::create('tomadores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conta_id')->constrained('contas');
            $table->string('documento', 14);
            $table->string('nome', 300);
            $table->string('nome_fantasia', 300)->nullable();
            $table->string('logradouro', 255)->nullable();
            $table->string('numero', 60)->nullable();
            $table->string('complemento', 156)->nullable();
            $table->string('bairro', 156)->nullable();
            $table->string('municipio', 120)->nullable();
            $table->string('codigo_municipio', 7)->nullable();
            $table->string('uf', 2)->nullable();
            $table->string('cep', 8)->nullable();
            $table->json('emails')->nullable();
            $table->text('observacoes')->nullable();
            $table->boolean('pendente')->default(false);   // cadastro incompleto para emissão
            $table->timestamps();
            $table->unique(['conta_id', 'documento']);
        });

        // Histórico: quem mudou o quê, de qual valor para qual.
        Schema::create('tomador_alteracoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conta_id')->constrained('contas');
            $table->foreignId('tomador_id')->constrained('tomadores')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('importacao_id')->nullable()->constrained('importacoes')->nullOnDelete();
            $table->string('origem', 20);                   // importacao | manual
            $table->json('campos');                         // {campo: [de, para]}
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tomador_id', 'created_at']);
        });

        Schema::table('notas_fiscais', function (Blueprint $table) {
            $table->foreignId('tomador_id')->nullable()->after('emitente_id')->constrained('tomadores')->nullOnDelete();
            $table->boolean('sem_tomador')->default(false)->after('tomador_email');   // "sem destinatário informado"
        });

        // Dados fiscais que a planilha não repete linha a linha
        Schema::table('emitentes', function (Blueprint $table) {
            $table->text('descricao_servico_padrao')->nullable()->after('codigo_servico_padrao');
            $table->string('codigo_nbs_padrao', 9)->nullable()->after('descricao_servico_padrao');
        });

        // Avisos da leitura (totais, etc.) e a prévia das mudanças no cadastro, aplicada só ao confirmar
        Schema::table('importacoes', function (Blueprint $table) {
            $table->json('avisos')->nullable();
            $table->json('atualizacoes')->nullable();
            $table->timestamp('cadastro_aplicado_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('importacoes', fn (Blueprint $t) => $t->dropColumn(['avisos', 'atualizacoes', 'cadastro_aplicado_em']));
        Schema::table('emitentes', fn (Blueprint $t) => $t->dropColumn(['descricao_servico_padrao', 'codigo_nbs_padrao']));
        Schema::table('notas_fiscais', function (Blueprint $t) {
            $t->dropConstrainedForeignId('tomador_id');
            $t->dropColumn('sem_tomador');
        });
        Schema::dropIfExists('tomador_alteracoes');
        Schema::dropIfExists('tomadores');
    }
};

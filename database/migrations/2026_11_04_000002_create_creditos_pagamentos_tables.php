<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Créditos (carteira + lotes + extrato imutável), pacotes e pagamentos Pix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pacotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('revenda_id')->constrained('revendas');
            $table->string('nome');
            $table->unsignedInteger('creditos');
            $table->unsignedInteger('preco_centavos');
            $table->unsignedSmallInteger('validade_dias')->nullable(); // null = não expira
            $table->boolean('ativo')->default(true);
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->timestamps();
        });

        Schema::create('pagamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conta_id')->constrained('contas');
            $table->foreignId('pacote_id')->nullable()->constrained('pacotes');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('valor_centavos');
            $table->unsignedInteger('creditos');
            $table->unsignedSmallInteger('validade_dias')->nullable();
            $table->string('status')->default('pendente')->index();   // App\Enums\StatusPagamento
            $table->string('gateway');
            $table->string('gateway_id')->nullable()->unique();
            $table->text('pix_copia_cola')->nullable();
            $table->longText('pix_qr_base64')->nullable();
            $table->json('split')->nullable();
            $table->timestamp('expira_em')->nullable();
            $table->timestamp('pago_em')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_eventos', function (Blueprint $table) {
            $table->id();
            $table->string('gateway');
            $table->string('evento_id');
            $table->string('tipo');
            $table->json('conteudo');
            $table->timestamp('processado_em')->nullable();
            $table->timestamps();
            $table->unique(['gateway', 'evento_id']);                 // webhook repetido não processa 2x
        });

        Schema::create('carteiras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conta_id')->unique()->constrained('contas')->cascadeOnDelete();
            $table->integer('disponivel')->default(0);                // pode ficar negativo após devolução de Pix
            $table->unsignedInteger('reservado')->default(0);
            $table->timestamps();
        });

        Schema::create('lotes_credito', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conta_id')->constrained('contas')->cascadeOnDelete();
            $table->string('origem');                                  // compra | bonus | ajuste
            $table->foreignId('pagamento_id')->nullable()->unique()->constrained('pagamentos');
            $table->unsignedInteger('quantidade');
            $table->unsignedInteger('restante');
            $table->timestamp('expira_em')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('movimentos_credito', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conta_id')->constrained('contas')->cascadeOnDelete();
            $table->string('tipo');                                    // App\Enums\TipoMovimento
            $table->integer('quantidade');                             // sinal = efeito no disponível
            $table->integer('disponivel_apos');
            $table->unsignedInteger('reservado_apos');
            $table->foreignId('lote_credito_id')->nullable()->constrained('lotes_credito');
            $table->unsignedBigInteger('nota_fiscal_id')->nullable();  // FK criada depois de notas_fiscais
            $table->foreignId('pagamento_id')->nullable()->constrained('pagamentos');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('ciclo')->default(1);              // reenvio de nota = novo ciclo
            $table->string('descricao')->nullable();
            $table->timestamp('created_at')->useCurrent();             // imutável: sem updated_at
            $table->index(['conta_id', 'created_at']);
            // Sem cobrança dupla: uma reserva/consumo/estorno por nota e por ciclo
            $table->unique(['tipo', 'nota_fiscal_id', 'ciclo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimentos_credito');
        Schema::dropIfExists('lotes_credito');
        Schema::dropIfExists('carteiras');
        Schema::dropIfExists('webhook_eventos');
        Schema::dropIfExists('pagamentos');
        Schema::dropIfExists('pacotes');
    }
};

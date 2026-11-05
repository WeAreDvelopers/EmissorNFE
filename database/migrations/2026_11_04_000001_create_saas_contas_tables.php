<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 (SaaS) + estrutura da fase 4 (white-label):
 * revendas → contas → (emitentes, usuários, carteira)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revendas', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('slug')->unique();
            $table->string('dominio')->nullable()->unique();          // emissor.revenda.com.br (fase 4)
            $table->json('marca')->nullable();                         // logo, cores, e-mail remetente
            $table->unsignedInteger('preco_atacado_credito_centavos')->default(0);
            $table->string('gateway_carteira_id')->nullable();         // walletId da subconta (split)
            $table->boolean('padrao')->default(false);                 // venda direta
            $table->string('status')->default('ativa');                // ativa | suspensa
            $table->timestamps();
        });

        Schema::create('contas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('revenda_id')->constrained('revendas');
            $table->string('nome');
            $table->string('documento', 14)->nullable();               // CPF/CNPJ de quem paga
            $table->string('email_financeiro')->nullable();
            $table->string('gateway_cliente_id')->nullable();          // cliente no Asaas
            $table->unsignedInteger('limite_aviso_saldo')->default(10);
            $table->string('status')->default('ativa');                // ativa | suspensa | bloqueada_saldo_negativo
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('telefone', 20)->nullable()->unique();      // E.164, para WhatsApp (fase 3)
            $table->timestamp('telefone_verificado_em')->nullable();
            $table->foreignId('conta_atual_id')->nullable()->constrained('contas')->nullOnDelete();
            $table->boolean('admin_plataforma')->default(false);
        });

        Schema::create('conta_usuario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conta_id')->constrained('contas')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('papel');                                   // App\Enums\Papel
            $table->timestamps();
            $table->unique(['conta_id', 'user_id']);
        });

        Schema::create('convites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conta_id')->constrained('contas')->cascadeOnDelete();
            $table->foreignId('convidado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email');
            $table->string('papel');
            $table->string('token', 64)->unique();
            $table->timestamp('expira_em');
            $table->timestamp('aceito_em')->nullable();
            $table->timestamps();
        });

        Schema::create('emitentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conta_id')->constrained('contas')->cascadeOnDelete();
            $table->string('cnpj', 14);
            $table->string('razao_social');
            $table->string('inscricao_municipal', 15)->nullable();     // só com cadastro no CNC (E0116/E0120)
            $table->string('codigo_municipio', 7);
            $table->unsignedTinyInteger('op_simples_nacional');        // 1 | 2 | 3
            $table->unsignedTinyInteger('regime_apuracao_sn')->nullable();
            $table->unsignedTinyInteger('regime_especial')->default(0);
            $table->decimal('aliquota_simples', 5, 2)->nullable();
            $table->json('tributos_aprox')->nullable();                // {federal, estadual, municipal}
            $table->string('serie_dps', 5)->default('00001');
            $table->unsignedTinyInteger('ambiente')->default(2);       // 2 restrita | 1 produção
            $table->string('certificado_arquivo')->nullable();         // caminho do .pfx criptografado
            $table->text('certificado_senha')->nullable();             // cast encrypted
            $table->timestamp('certificado_validade')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->unique(['conta_id', 'cnpj']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emitentes');
        Schema::dropIfExists('convites');
        Schema::dropIfExists('conta_usuario');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('conta_atual_id');
            $table->dropColumn(['telefone', 'telefone_verificado_em', 'admin_plataforma']);
        });
        Schema::dropIfExists('contas');
        Schema::dropIfExists('revendas');
    }
};

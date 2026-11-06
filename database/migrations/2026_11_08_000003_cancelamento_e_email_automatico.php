<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cancelamento de NFS-e (evento 101101): quem, quando, por quê e o XML do evento registrado
        Schema::table('notas_fiscais', function (Blueprint $table) {
            $table->timestamp('cancelada_em')->nullable();
            $table->string('cancelamento_codigo', 1)->nullable();      // 1 erro na emissão | 2 serviço não prestado | 9 outros
            $table->string('cancelamento_motivo', 255)->nullable();
            $table->longText('xml_cancelamento')->nullable();
        });

        // Envio automático da NFS-e ao cliente quando a nota é emitida (só em produção)
        Schema::table('emitentes', fn (Blueprint $t) => $t->boolean('enviar_email_automatico')->default(false));
    }

    public function down(): void
    {
        Schema::table('notas_fiscais', fn (Blueprint $t) => $t->dropColumn(['cancelada_em', 'cancelamento_codigo', 'cancelamento_motivo', 'xml_cancelamento']));
        Schema::table('emitentes', fn (Blueprint $t) => $t->dropColumn('enviar_email_automatico'));
    }
};

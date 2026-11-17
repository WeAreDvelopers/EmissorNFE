<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A auditoria passa a saber de qual empresa (emitente) é cada registro, para a tela respeitar a empresa selecionada no topo.
     * Registros antigos: descobre a empresa pela nota, lote ou cliente citados; os de empresa, pelo nome na descrição.
     * O que não der para descobrir (ex.: usuários) fica sem empresa = geral da conta.
     */
    public function up(): void
    {
        Schema::table('auditoria', function (Blueprint $table) {
            $table->unsignedBigInteger('emitente_id')->nullable()->after('conta_id')->index();
        });

        DB::table('auditoria')->whereNull('emitente_id')->orderBy('id')->each(function ($r) {
            $d = json_decode((string) $r->detalhes, true) ?: [];
            $emitente = null;
            if (isset($d['nota'])) {
                $emitente = DB::table('notas_fiscais')->where('id', $d['nota'])->value('emitente_id');
            } elseif (isset($d['lote'])) {
                $emitente = DB::table('importacoes')->where('id', $d['lote'])->value('emitente_id');
            } elseif (isset($d['tomador'])) {
                $emitente = DB::table('tomadores')->where('id', $d['tomador'])->value('emitente_id');
            } elseif (in_array($r->acao, ['empresa_criada', 'empresa_alterada', 'numeracao_ajustada'], true) && preg_match('/:\s*(.+?)\.?$/u', $r->descricao, $m)) {
                $emitente = DB::table('emitentes')->where('conta_id', $r->conta_id)->whereRaw('lower(razao_social) = ?', [mb_strtolower(trim($m[1]))])->value('id');
            }
            if ($emitente) {
                DB::table('auditoria')->where('id', $r->id)->update(['emitente_id' => $emitente]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('auditoria', fn (Blueprint $table) => $table->dropColumn('emitente_id'));
    }
};

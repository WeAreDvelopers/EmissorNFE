<?php

namespace App\Services\Nfse;

use App\Enums\StatusNota;
use App\Models\NotaFiscal;

/**
 * Procura uma nota que pareça a mesma cobrança: mesma empresa, mesmo cliente (CPF/CNPJ), mesmo valor e mesma data.
 * O XLS não traz um identificador único da cobrança, então isto é só um ALERTA para conferência:
 * quem decide se é duplicada é o usuário. Linhas "sem destinatário" não têm cliente para comparar e ficam de fora.
 */
class DetectorDuplicidade
{
    /** Marca a nota se achar uma parecida. Devolve a nota encontrada (ou null). */
    public function marcar(NotaFiscal $nota): ?NotaFiscal
    {
        if ($nota->sem_tomador || $nota->tomador_documento === '') {
            return null;
        }

        $parecida = NotaFiscal::where('emitente_id', $nota->emitente_id)
            ->where('tomador_documento', $nota->tomador_documento)
            ->where('valor', $nota->valor)
            ->whereDate('data_competencia', $nota->data_competencia)
            ->whereKeyNot($nota->id)
            ->where(function ($q) use ($nota) {
                // Já emitida ou a caminho (em qualquer lote), ou outra linha do mesmo arquivo lida antes desta
                $q->whereIn('status', [StatusNota::Emitida, StatusNota::Pendente, StatusNota::Enviando, StatusNota::AguardandoCredito])
                  ->orWhere(fn ($q) => $q->where('importacao_id', $nota->importacao_id)->where('id', '<', $nota->id)->where('status', StatusNota::Rascunho));
            })
            ->orderByDesc('id')->first();

        if ($parecida) {
            $nota->forceFill(['duplicidade_de_id' => $parecida->id, 'duplicidade_aprovada' => false])->save();
        }

        return $parecida;
    }
}

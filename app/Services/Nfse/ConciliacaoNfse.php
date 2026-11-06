<?php

namespace App\Services\Nfse;

use App\Enums\StatusNota;
use App\Models\Emitente;
use App\Models\NotaFiscal;

/**
 * Conferência com a SEFIN das notas com resultado incerto (a resposta se perdeu, o worker caiu, a rede falhou).
 * Consulta pelo identificador ORIGINAL da DPS (nunca gera outro) e, se já foi autorizada, fecha a nota.
 */
class ConciliacaoNfse
{
    public const JA_EMITIDA = 'ja_emitida';
    public const SEM_ENVIO = 'sem_envio';
    public const AUTORIZADA = 'autorizada';
    public const NAO_ENCONTRADA = 'nao_encontrada';

    public function __construct(private FinalizadorNota $finalizador) {}

    public function conciliar(NotaFiscal $nota): string
    {
        if ($nota->status === StatusNota::Emitida) {
            return self::JA_EMITIDA;
        }
        if (! $nota->numero_dps) {
            return self::SEM_ENVIO;             // nunca chegou a ser enviada: não há o que consultar
        }

        $emitente = Emitente::semIsolamento()->findOrFail($nota->emitente_id);
        $nota->setRelation('emitente', $emitente);

        $retorno = $this->cliente($emitente)->consultarPorDps((new DpsBuilder)->idDps($nota));

        $nota->forceFill(['conciliada_em' => now()])->save();

        if (! $retorno) {
            return self::NAO_ENCONTRADA;
        }

        $this->finalizador->emitida($nota, $retorno, cobrarSeNecessario: true);

        return self::AUTORIZADA;
    }

    protected function cliente(Emitente $emitente): SefinNacionalClient
    {
        return SefinNacionalClient::para($emitente);
    }

    /** Notas com resultado incerto que valem uma consulta agora. */
    public function candidatas(int $limite = 100)
    {
        return NotaFiscal::semIsolamento()->whereNotNull('numero_dps')
            ->where(function ($q) {
                $q->where(fn ($q) => $q->where('status', StatusNota::Enviando)->where('updated_at', '<', now()->subMinutes(30)))
                  ->orWhere(fn ($q) => $q->where('status', StatusNota::Pendente)->where('updated_at', '<', now()->subHours(2)))
                  ->orWhere(fn ($q) => $q->where('status', StatusNota::Falhou)->where('updated_at', '>', now()->subDays(7)));
            })
            ->where(fn ($q) => $q->whereNull('conciliada_em')->orWhere('conciliada_em', '<', now()->subHours(3)))
            ->orderBy('id')->limit($limite)->get();
    }
}

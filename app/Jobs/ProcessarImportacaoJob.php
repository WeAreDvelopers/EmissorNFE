<?php

namespace App\Jobs;

use App\Models\Importacao;
use App\Services\Nfse\ImportadorPlanilha;
use App\Support\Tenancy\ContaAtual;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Lê e valida a planilha em background. Não gasta crédito e não emite nada:
 * ao terminar a importação fica em "conferir", esperando o usuário revisar e confirmar o envio.
 * Fica na fila "default"; a emissão roda na fila "nfse".
 */
class ProcessarImportacaoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;          // não reprocessar a planilha inteira (geraria notas duplicadas)
    public int $timeout = 600;

    public function __construct(public Importacao $importacao) {}

    public function handle(): void
    {
        // Fora de requisição não há conta ativa: entra na conta da importação
        app(ContaAtual::class)->como($this->importacao->conta_id, function () {
            $this->importacao->update(['status' => 'lendo']);

            app(ImportadorPlanilha::class)->importar($this->importacao->load('emitente'));

            $this->importacao->update(['status' => 'conferir']);
        });
    }

    public function failed(Throwable $e): void
    {
        Importacao::semIsolamento()->whereKey($this->importacao->id)->update([
            'status' => 'falhou',
            'erro'   => $this->mensagemAmigavel($e),
        ]);
    }

    private function mensagemAmigavel(Throwable $e): string
    {
        // Erro já escrito para o usuário (aba/cabeçalho fora do modelo, CNPJ de A1 errado)
        if ($e instanceof \RuntimeException) {
            return $e->getMessage();
        }

        return 'Não conseguimos ler a planilha. Confira se ela segue o modelo de notas (cabeçalhos na linha 2 e dados a partir da linha 3).'
            .(config('app.debug') ? ' ('.$e->getMessage().')' : '');
    }
}

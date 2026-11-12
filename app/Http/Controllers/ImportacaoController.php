<?php

namespace App\Http\Controllers;

use App\Enums\StatusNota;
use App\Http\Requests\ImportarPlanilhaRequest;
use App\Jobs\EnviarImportacaoJob;
use App\Jobs\ProcessarImportacaoJob;
use App\Models\Emitente;
use App\Models\Importacao;
use App\Models\NotaFiscal;
use App\Services\Creditos\CarteiraService;
use App\Services\Nfse\EnvioDeNotas;
use App\Support\Ordenacao;
use App\Services\Tomadores\CadastroTomadores;
use App\Support\ExplicacaoErro;
use App\Support\Tenancy\ContaAtual;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ImportacaoController extends Controller
{
    public function __construct(private ContaAtual $contaAtual) {}

    /** Lista de lotes, com resumo por lote e os números do topo. */
    public function index(Request $request)
    {
        $this->authorize('consultar');
        $consulta = Importacao::with('emitente')->latest();
        [$ordem, $dir] = Ordenacao::aplicar($consulta, $request, ['arquivo' => 'arquivo_nome', 'data' => 'created_at', 'situacao' => 'status']);
        $importacoes = $consulta->paginate(20)->withQueryString();

        $resumos = NotaFiscal::whereIn('importacao_id', $importacoes->pluck('id'))
            ->selectRaw('importacao_id, status, count(*) as total')
            ->groupBy('importacao_id', 'status')->get()
            ->groupBy('importacao_id')
            ->map(fn ($linhas) => collect(StatusNota::cases())->mapWithKeys(
                fn ($s) => [$s->value => (int) ($linhas->firstWhere('status', $s->value)->total ?? 0)]
            )->all());

        return view('importacoes.index', compact('importacoes', 'resumos', 'ordem', 'dir'));
    }

    /** Passo 1: escolher o arquivo. */
    public function criar()
    {
        $this->authorize('emitir');

        return view('importacoes.criar', [
            'emitentes' => Emitente::where('ativo', true)->whereKey(app(\App\Support\Tenancy\EmitenteAtual::class)->id())->get(),   // só a empresa ativa
        ]);
    }

    /** Recebe a planilha, guarda e manda LER (não emite nada ainda). Responde na hora. */
    public function store(ImportarPlanilhaRequest $request)
    {
        $this->authorize('emitir');

        $arquivo = $request->file('planilha');
        $hash = hash_file('sha256', $arquivo->getRealPath());

        // Arquivo idêntico já importado para esta empresa: não existe "enviar mesmo assim". As notas dele continuam lá
        // (corrigir, reenviar, consultar); só uma leitura que falhou pode ser refeita com o mesmo arquivo.
        $repetida = Importacao::where('emitente_id', $request->emitente_id)->where('arquivo_hash', $hash)->where('status', '!=', 'falhou')->latest('id')->first();
        if ($repetida) {
            $situacao = $repetida->foiEnviada() ? 'e as notas dela já foram enviadas' : 'e ainda aguarda a sua conferência';

            return back()->withInput()->withErrors([
                'planilha' => "Este arquivo já foi importado em {$repetida->created_at->format('d/m/Y H:i')} {$situacao}. Importar de novo geraria notas em dobro, então não é permitido. Abra a importação para ver, corrigir ou reenviar o que precisar.",
            ])->with('importacao_repetida', $repetida->id);
        }

        $importacao = Importacao::create([
            'emitente_id'     => $request->emitente_id,   // já validado como emitente da conta ativa
            'user_id'         => $request->user()?->id,
            'arquivo_nome'    => $arquivo->getClientOriginalName(),
            'arquivo_caminho' => $arquivo->store('importacoes'),
            'arquivo_hash'    => $hash,
            'status'          => 'pendente',
        ]);

        ProcessarImportacaoJob::dispatch($importacao);

        return redirect()->route('importacoes.conferir', $importacao);
    }

    /** Passo 2: conferir. Mostra o que está pronto, o que tem problema e quanto vai custar, antes de gastar crédito. */
    public function conferir(Request $request, Importacao $importacao, CarteiraService $creditos)
    {
        $this->authorize('consultar');

        // Já enviada? Vai direto para o acompanhamento.
        if (in_array($importacao->status, ['enviando', 'enviada', 'concluida'], true)) {
            return redirect()->route('importacoes.show', $importacao);
        }

        $importacao->load('emitente');
        $resumo = $importacao->resumo();
        $saldo = $creditos->saldo($this->contaAtual->id())->disponivel;
        $prontas = $resumo['rascunho'];

        $invalidas = NotaFiscal::with('emitente')
            ->where('importacao_id', $importacao->id)->where('status', StatusNota::Invalida)
            ->orderBy('linha_planilha')->limit(20)->get()
            ->each(fn ($n) => $n->explicacao = ExplicacaoErro::para($n));

        // Todas as linhas da planilha, com todas as colunas, para o usuário conferir o que vai ser emitido
        $ver = $request->query('ver', 'todas');
        $linhas = $importacao->notas()->orderBy('linha_planilha');
        match ($ver) {
            'prontas'   => $linhas->where('status', StatusNota::Rascunho),
            'problemas' => $linhas->where('status', StatusNota::Invalida),
            default     => null,
        };

        [$ordem, $dir] = Ordenacao::aplicar($linhas, $request, ['linha' => 'linha_planilha', 'situacao' => 'status', 'cliente' => 'tomador_nome', 'servico' => 'codigo_servico', 'descricao' => 'descricao', 'valor' => 'valor', 'competencia' => 'data_competencia']);

        $pagina = $linhas->paginate(50, pageName: 'pagina')->withQueryString();

        // O que está errado em cada campo de cada linha da página (vermelho + tooltip na tela)
        $errosCampo = [];
        foreach ($pagina as $l) {
            if ($l->status !== StatusNota::Invalida) {
                continue;
            }
            $erros = array_values((array) $l->erros);
            if (str_starts_with((string) ($erros[0] ?? ''), \App\Services\Nfse\EnvioDeNotas::CADASTRO_INCOMPLETO)) {
                $errosCampo[$l->id] = ['tomador_documento' => $erros[0].' Não emitimos nota para cliente sem endereço completo e ao menos 1 e-mail.'];
                continue;
            }
            $dados = \App\Support\ValidadorLinha::normalizar($l->only(\App\Support\ValidadorLinha::CAMPOS) + ['data_competencia' => $l->data_competencia?->toDateString()], $importacao->emitente);
            $mensagens = collect(\App\Support\ValidadorLinha::validar($dados, $importacao->emitente)->errors()->messages())->map(fn ($m) => implode(' ', $m))->all();
            $errosCampo[$l->id] = $mensagens ?: ['_linha' => implode(' ', $erros) ?: 'Algum dado desta linha não passou na conferência.'];
        }

        return view('importacoes.conferir', [
            'ordem' => $ordem, 'dir' => $dir,
            'errosCampo' => $errosCampo,
            'linhasComProblema' => $importacao->notas()->where('status', StatusNota::Invalida)->orderBy('linha_planilha')->limit(40)->get(['id', 'linha_planilha']),
            'linhas'     => $pagina,
            'ver'        => $ver,
            'importacao' => $importacao,
            'prontas'    => $prontas,
            'invalidas'  => $invalidas,
            'qtdInvalidas' => $resumo['invalida'],
            'saldo'      => $saldo,
            'saldoDepois' => $saldo - $prontas,
            'semCusto'   => $importacao->emitente->semCobranca(),
            'semSaldo'   => ! $importacao->emitente->semCobranca() && $saldo < $prontas,
            'cobrancaDesligada' => ! $importacao->emitente->ehHomologacao() && $importacao->emitente->cobranca_desligada,
            'duplicidades' => NotaFiscal::with('duplicadaDe.importacao')->where('importacao_id', $importacao->id)->comDuplicidadePendente()->orderBy('linha_planilha')->get(),
            'total'      => array_sum($resumo),
            'somaValores' => (float) NotaFiscal::where('importacao_id', $importacao->id)->where('status', StatusNota::Rascunho)->sum('valor'),
        ]);
    }

    /** Passo 3: enviar. Só agora o crédito é reservado e as notas entram na fila. */
    public function enviar(Request $request, Importacao $importacao)
    {
        $this->authorize('emitir');
        abort_unless($importacao->status === 'conferir', 422, 'Esta importação não está aguardando conferência.');

        if ($request->input('modo') === 'corrigir_antes') {
            return back()->with('ok', 'Tudo bem. Nada foi enviado. Corrija as linhas e envie quando quiser.');
        }

        abort_if($importacao->resumo()['rascunho'] === 0, 422, 'Não há notas prontas para enviar.');

        // Cobrança que parece repetida precisa de uma decisão sua (manter ou excluir) antes de qualquer envio
        $pendentes = $importacao->notas()->comDuplicidadePendente()->count();
        if ($pendentes > 0) {
            $msg = "Resolva as {$pendentes} possíveis duplicidades antes de enviar: aprove a linha se for outra cobrança, ou exclua.";

            return $request->expectsJson() ? response()->json(['message' => $msg], 422) : back()->withErrors(['duplicidade' => $msg]);
        }

        // Só agora o cadastro de clientes é atualizado (a prévia foi mostrada na conferência) e fica no histórico
        app(CadastroTomadores::class)->aplicar($importacao, $request->user()?->id);

        $importacao->update(['status' => 'enviando']);
        \App\Models\Auditoria::registrar('importacao_enviada', "Lote {$importacao->arquivo_nome} enviado para emissão.", [
            'lote' => $importacao->id, 'notas_prontas' => $importacao->resumo()['rascunho'], 'empresa' => $importacao->emitente?->razao_social,
            'ambiente' => $importacao->emitente?->ehHomologacao() ? 'teste' : 'produção',
        ]);
        EnviarImportacaoJob::dispatch($importacao);

        return redirect()->route('importacoes.show', $importacao)
            ->with('ok', 'Notas enviadas para emissão. Você pode fechar a página: avisamos quando terminar.');
    }

    /** Acompanhamento do lote. */
    public function show(Request $request, Importacao $importacao)
    {
        $this->authorize('consultar');

        // Ainda não enviada: o lugar certo é a conferência
        if (in_array($importacao->status, ['pendente', 'lendo', 'conferir'], true)) {
            return redirect()->route('importacoes.conferir', $importacao);
        }

        $importacao->load('emitente');
        $resumo = $importacao->resumo();
        $filtro = $request->query('filtro', 'todas');

        $consulta = $importacao->notas()->orderBy('linha_planilha');
        match ($filtro) {
            'emitidas'  => $consulta->where('status', StatusNota::Emitida),
            'andamento' => $consulta->whereIn('status', StatusNota::andamento()),
            'atencao'   => $consulta->whereIn('status', StatusNota::atencao()),
            default     => null,
        };
        [$ordem, $dir] = Ordenacao::aplicar($consulta, $request, ['linha' => 'linha_planilha', 'cliente' => 'tomador_nome', 'valor' => 'valor', 'situacao' => 'status']);
        $notas = $consulta->paginate(30)->withQueryString();

        $selecionada = $request->integer('nota')
            ? NotaFiscal::with('emitente')->where('importacao_id', $importacao->id)->find($request->integer('nota'))
            : null;

        $andamento = $resumo['pendente'] + $resumo['enviando'] + $resumo['aguardando_credito'];
        $limite = max((int) config('nfse.limite_por_minuto', 60), 1);

        return view('importacoes.show', [
            'ordem' => $ordem, 'dir' => $dir,
            'importacao'  => $importacao,
            'resumo'      => $resumo,
            'filtro'      => $filtro,
            'notas'       => $notas,
            'selecionada' => $selecionada,
            'total'       => array_sum($resumo) - $resumo['rascunho'],
            'andamento'   => $andamento,
            'atencao'     => $resumo['invalida'] + $resumo['rejeitada'] + $resumo['falhou'],
            'minutos'     => (int) ceil(($resumo['pendente'] + $resumo['enviando']) / $limite),
        ]);
    }

    /** JSON para atualizar a tela sem recarregar (polling). */
    public function progresso(Importacao $importacao)
    {
        return response()->json([
            'status' => $importacao->status,
            'total'  => $importacao->total_linhas,
            'resumo' => $importacao->resumo(),
        ]);
    }

    /** Reenvia uma nota que falhou por erro técnico (mesmo número de DPS). */
    public function reenviar(NotaFiscal $nota, EnvioDeNotas $envio)
    {
        $this->authorize('emitir');
        $cadastro = $nota->status === StatusNota::Invalida && str_starts_with((string) (((array) $nota->erros)[0] ?? ''), EnvioDeNotas::CADASTRO_INCOMPLETO);
        abort_unless($nota->status === StatusNota::Falhou || $cadastro, 422, 'Só notas com falha técnica ou cadastro de cliente incompleto podem ser reenviadas.');

        $envio->enviarNota($nota);

        $msg = $nota->fresh()->status === StatusNota::AguardandoCredito
            ? "{$nota->origem_rotulo} vai para a fila assim que houver crédito."
            : "{$nota->origem_rotulo} reenviada.";

        return request()->expectsJson() ? response()->json(['ok' => true, 'mensagem' => $msg]) : back()->with('ok', $msg);
    }

    /** Reenvia de uma vez todas as notas que falharam por instabilidade. */
    public function reenviarFalhas(Importacao $importacao, EnvioDeNotas $envio)
    {
        $this->authorize('emitir');

        $qtd = 0;
        $importacao->notas()->where('status', StatusNota::Falhou)->eachById(function (NotaFiscal $nota) use ($envio, &$qtd) {
            $envio->enviarNota($nota);
            $qtd++;
        });

        $msg = $qtd ? "{$qtd} notas reenviadas." : 'Não há notas para reenviar.';

        return request()->expectsJson() ? response()->json(['ok' => true, 'mensagem' => $msg]) : back()->with('ok', $msg);
    }

    /**
     * Exclui um lote que não emitiu nada e não está na fila. Os créditos já foram devolvidos
     * (recusadas/falhas estornam), então a conta fica igual; o histórico de créditos é preservado.
     */
    public function excluir(Request $request, Importacao $importacao)
    {
        $this->authorize('emitir');

        abort_if($importacao->status === 'lendo', 422, 'Estamos lendo esta planilha agora. Tente excluir em alguns segundos.');

        $bloqueadas = $importacao->notas()
            ->whereIn('status', [StatusNota::Emitida, StatusNota::Cancelada, StatusNota::Pendente, StatusNota::Enviando, StatusNota::AguardandoCredito])
            ->count();
        abort_if($bloqueadas > 0, 422, "Este lote tem {$bloqueadas} ".($bloqueadas === 1 ? 'nota emitida ou na fila' : 'notas emitidas ou na fila').' e não pode ser excluído.');

        $nome = $importacao->arquivo_nome;
        $caminho = $importacao->arquivo_caminho;
        \App\Models\Auditoria::registrar('importacao_excluida', "Lote {$nome} excluído.", ['lote' => $importacao->id, 'linhas' => $importacao->total_linhas]);

        DB::transaction(function () use ($importacao) {
            DB::table('movimentos_credito')->whereIn('nota_fiscal_id', $importacao->notas()->select('id'))->update(['nota_fiscal_id' => null]);
            $importacao->notas()->delete();
            $importacao->delete();   // lotes reprocessados a partir deste ficam sem origem (nullOnDelete)
        });

        // O arquivo pode ser compartilhado com um reprocesso: só apaga se ninguém mais usa
        if ($caminho && ! Importacao::semIsolamento()->where('arquivo_caminho', $caminho)->exists()) {
            Storage::delete($caminho);
        }

        $msg = "Importação “{$nome}” excluída.";

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'mensagem' => $msg])
            : redirect()->route('importacoes.index')->with('ok', $msg);
    }

    /** Planilha modelo no formato dos ERPs: CNPJ em A1, totais em E1/F1, cabeçalhos na linha 2, notas a partir da linha 3. */
    public function modelo(Request $request)
    {
        $this->authorize('consultar');

        // O CNPJ da empresa vem preenchido em A1: é com ele que o app confere se a planilha é da empresa certa
        // Sempre o modelo da empresa que está sendo administrada (não dá para baixar o de outra)
        $empresa = app(\App\Support\Tenancy\EmitenteAtual::class)->emitente();

        $livro = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $aba = $livro->getActiveSheet()->setTitle('Notas');
        $cabecalhos = ['Data de Competência', 'Nome Fantasia (ref.)', 'Razão Social/Nome (ref.)', 'CNPJ/CPF', 'Valor (R$)', 'Imposto IBPT', 'Atualizar',
            'Nome Fantasia (novo)', 'Razão Social/Nome (novo)', 'Logradouro', 'Número', 'Complemento', 'Bairro', 'Município', 'UF', 'CEP', 'E-mail',
            'Descrição dos Serviços (Opcional)', 'Código de Serviço (Opcional)', 'NBS (Opcional)'];

        $aba->setCellValue('A1', $empresa ? \App\Support\Documento::formatar($empresa->cnpj) : '00.000.000/0000-00');   // CNPJ da empresa que emite
        $aba->setCellValueExplicit('A1', $empresa ? \App\Support\Documento::formatar($empresa->cnpj) : '00.000.000/0000-00', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        if ($empresa) {
            $aba->setCellValue('B1', $empresa->razao_social);                // só para quem abre a planilha; o app lê apenas A1, E1 e F1
        }
        $aba->getStyle('A1:D1')->getFont()->setBold(true)->setSize(13);
        $aba->getStyle('A1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('DDEFE3');
        $aba->setCellValue('E1', '=SUM(E3:E200)')->setCellValue('F1', '=SUM(F3:F200)');
        foreach ($cabecalhos as $i => $titulo) {
            $aba->setCellValue([$i + 1, 2], $titulo);
        }
        $hoje = \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(now());
        $exemplos = [
            [$hoje, 'Padaria Bom Grão', 'Padaria Bom Grão Ltda', '12.345.678/0001-95', 1500, '=E3*0.1759', 'x', '', '', 'Rua das Flores', '120', 'Sala 2', 'Centro', 'São Paulo', 'SP', '01001-000', 'financeiro@exemplo.com.br, contato@exemplo.com.br', '', '', ''],
            [$hoje, '', '', 'sem destinatário informado', 300, '=E4*0.1759', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
        ];
        foreach ($exemplos as $l => $linha) {
            foreach ($linha as $c => $valor) {
                if ($valor !== '') {
                    $aba->setCellValue([$c + 1, $l + 3], $valor);
                }
            }
        }
        $aba->getStyle('A3:A200')->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $cab = $aba->getStyle('A2:T2');
        $cab->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $cab->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('1F6F43');
        $cab->getAlignment()->setWrapText(true)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $aba->getStyle('H2:R2')->getFill()->getStartColor()->setRGB('2F855A');   // colunas que atualizam o cadastro (valem com x em "Atualizar")
        $aba->freezePane('A3');
        $aba->getStyle('E1:F200')->getNumberFormat()->setFormatCode('#,##0.00');
        foreach (range('A', 'T') as $col) {
            $aba->getColumnDimension($col)->setAutoSize(true);
        }

        return response()->streamDownload(
            fn () => (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($livro))->save('php://output'),
            'modelo-notas-'.($empresa ? $empresa->cnpj : 'erp').'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }
}

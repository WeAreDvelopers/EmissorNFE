<?php

namespace App\Services\Tomadores;

use App\Models\Tomador;
use App\Models\TomadorAlteracao;
use App\Support\Documento;
use App\Support\MunicipiosIbge;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Cadastro de clientes por planilha (sem emitir nota):
 *  - CPF/CNPJ novo → cria o cliente com o que a planilha traz (nome é obrigatório);
 *  - CPF/CNPJ existente → muda só o que a linha trouxe preenchido; célula vazia preserva o que já existe;
 *  - cada mudança entra no histórico do cliente.
 */
class ImportadorClientes
{
    /** Máximo de linhas por planilha (config nfse.limite_linhas_importacao). */
    public static function limite(): int
    {
        return (int) config('nfse.limite_linhas_importacao', 50);
    }

    /** Cabeçalho do modelo => campo. A leitura aceita variações (sem acento, maiúsculas, "E-mail"/"E-mails"). */
    public const COLUNAS = [
        'documento'     => 'CNPJ/CPF',
        'nome'          => 'Razão Social/Nome',
        'nome_fantasia' => 'Nome Fantasia',
        'logradouro'    => 'Logradouro',
        'numero'        => 'Número',
        'complemento'   => 'Complemento',
        'bairro'        => 'Bairro',
        'municipio'     => 'Município',
        'uf'            => 'UF',
        'cep'           => 'CEP',
        'emails'        => 'E-mails',
        'observacoes'   => 'Observações',
    ];

    /**
     * Modelo para baixar. Linha 1: CNPJ e razão social do emitente (só para identificar de quem é a planilha; o app não os lê);
     * linha 2: cabeçalhos; a partir da linha 3: um tomador por linha. As regras detalhadas ficam numa aba protegida.
     */
    public function modelo(?\App\Models\Emitente $emitente = null): Spreadsheet
    {
        $livro = new Spreadsheet();
        $aba = $livro->getActiveSheet()->setTitle('Clientes');

        $aba->setCellValueExplicit('A1', $emitente ? Documento::formatar($emitente->cnpj) : '00.000.000/0000-00', DataType::TYPE_STRING);
        $aba->setCellValue('B1', $emitente ? $emitente->razao_social : 'Razão social do emitente');
        $aba->getStyle('A1:B1')->getFont()->setBold(true)->setSize(13);
        $aba->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDEFE3');

        $col = 1;
        foreach (self::COLUNAS as $titulo) {
            $aba->setCellValue([$col, 2], $titulo);
            $aba->getColumnDimensionByColumn($col)->setWidth(max(14, mb_strlen($titulo) + 6));
            $col++;
        }
        $ultima = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count(self::COLUNAS));
        $aba->getStyle("A2:{$ultima}2")->getFont()->setBold(true);
        $aba->getStyle("A2:{$ultima}2")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDEFE3');
        $aba->freezePane('A3');
        $aba->getColumnDimension('A')->setWidth(22);
        $aba->getColumnDimension('B')->setWidth(36);
        $aba->getColumnDimension('K')->setWidth(36);
        // CNPJ/CPF, número e CEP como texto: o Excel não pode comer os zeros da frente
        foreach (['A', 'E', 'J'] as $c) {
            $aba->getStyle("{$c}3:{$c}2000")->getNumberFormat()->setFormatCode('@');
        }

        $ajuda = $livro->createSheet()->setTitle('Como preencher');
        $linhas = [
            ['Como preencher a planilha de tomadores (clientes)'],
            [''],
            ['• Linha 1: CNPJ e razão social do emitente. Serve só para identificar de quem é a planilha; não precisa mexer.'],
            ['• Linha 2: cabeçalhos. Não mude os nomes nem a ordem. Preencha a aba "Clientes" a partir da linha 3, um tomador por linha.'],
            ['• CNPJ/CPF é obrigatório (com ou sem pontuação). Razão Social/Nome é obrigatório para tomador novo.'],
            ['• Tomador que já existe (mesmo CNPJ/CPF): só os campos preenchidos são atualizados. Célula vazia mantém o que já está cadastrado.'],
            ['• Para emitir nota o tomador precisa de endereço completo (logradouro, número, bairro, município, UF e CEP) e ao menos 1 e-mail.'],
            ['• E-mails: separe por vírgula ou ponto e vírgula. O primeiro é o principal e vai no XML da nota; o aviso de nota emitida vai para todos.'],
            ['• UF com 2 letras (ex.: SP). O código IBGE do município é descoberto sozinho pelo nome e pela UF (ou pelo CEP).'],
            ['• Linhas com problema são listadas depois da importação; as demais são importadas normalmente.'],
            ['• Até '.self::limite().' linhas por arquivo. Para importar mais, divida em mais de um arquivo.'],
        ];
        foreach ($linhas as $i => $l) {
            $ajuda->setCellValue([1, $i + 1], $l[0]);
        }
        $ajuda->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $ajuda->getColumnDimension('A')->setWidth(130);
        // Aba de regras protegida: o usuário lê, mas não edita
        $ajuda->getProtection()->setSheet(true)->setPassword('regras-modelo');
        $livro->setActiveSheetIndex(0);

        return $livro;
    }

    /** Campos mostrados na prévia, na ordem da planilha. */
    public const CAMPOS_PREVIA = ['documento', 'nome', 'nome_fantasia', 'logradouro', 'numero', 'complemento', 'bairro', 'municipio', 'uf', 'cep', 'emails', 'observacoes'];

    /**
     * Etapa 1 (nada é gravado): lê a planilha e diz, linha a linha, o que seria feito e o que está errado em cada campo.
     * - bloqueada: a linha não pode ser importada de jeito nenhum (CNPJ/CPF inutilizável, repetido, ou cliente novo sem nome);
     * - erros: campo => motivo. Na importação "com erros", o campo com erro fica de fora e o cadastro fica pendente.
     *
     * @return array<int,array{linha:int,bruto:array<string,string>,documento:?string,dados:array,erros:array<string,string>,bloqueada:bool,acao:?string}>
     */
    public function analisar(string $caminho): array
    {
        $saida = [];
        $vistos = [];

        foreach ($this->ler($caminho) as $numero => $l) {
            $item = [
                'linha' => $numero, 'documento' => null, 'dados' => [], 'erros' => [], 'bloqueada' => false, 'acao' => null,
                'bruto' => collect(self::CAMPOS_PREVIA)->mapWithKeys(fn ($c) => [$c => trim((string) ($l[$c] ?? ''))])->all(),
            ];

            $documento = $this->documento((string) ($l['documento'] ?? ''));
            if ($documento === null) {
                $item['erros']['documento'] = 'CNPJ/CPF vazio ou inválido (o dígito verificador não confere). Esta linha não pode ser importada.';
                $item['bloqueada'] = true;
            } elseif (isset($vistos[$documento])) {
                $item['erros']['documento'] = 'CNPJ/CPF repetido na planilha (já usado na linha '.$vistos[$documento].'). Esta linha não pode ser importada.';
                $item['bloqueada'] = true;
            } else {
                [$dados, $erros] = $this->normalizar($l);
                $item['documento'] = $documento;
                $item['dados'] = $dados;
                $item['erros'] = $erros;

                $tomador = Tomador::where('documento', $documento)->first();
                if (! $tomador && blank($dados['nome'] ?? null)) {
                    $item['erros']['nome'] = ($erros['nome'] ?? 'Cliente novo precisa de Razão Social/Nome.').' Cliente novo precisa de nome: esta linha não pode ser importada.';
                    $item['bloqueada'] = true;
                } else {
                    $item['acao'] = $tomador === null ? 'novo' : ($this->mudaria($tomador, $dados) ? 'atualizar' : 'sem_mudanca');
                    $vistos[$documento] = $numero;   // só conta como "visto" a linha que de fato entra
                }
            }

            $saida[] = $item;
        }

        return $saida;
    }

    /** O cadastro existente mudaria com estes dados? (mesma regra do gravar: vazio preserva, igual não muda) */
    private function mudaria(Tomador $tomador, array $dados): bool
    {
        foreach ($dados as $campo => $valor) {
            $antes = $tomador->{$campo};
            if (! ($antes === $valor || (blank($antes) && blank($valor)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Etapa 2: grava. $modo 'validos' = só as linhas sem nenhum erro; 'todos' = também as que têm erro em algum campo
     * (o campo com erro fica de fora e o cadastro fica "pendente de correção"). Linha bloqueada nunca entra.
     *
     * @return array{criados:int, atualizados:int, sem_mudanca:int, ressalvas:int, erros:array<int,array{linha:int,mensagem:string}>}
     */
    public function importar(string $caminho, ?int $userId, string $modo = 'validos'): array
    {
        $r = ['criados' => 0, 'atualizados' => 0, 'sem_mudanca' => 0, 'ressalvas' => 0, 'erros' => []];

        foreach ($this->analisar($caminho) as $item) {
            $motivo = implode(' ', $item['erros']);

            if ($item['bloqueada']) {
                $r['erros'][] = ['linha' => $item['linha'], 'mensagem' => $motivo];
                continue;
            }
            if ($item['erros'] && $modo !== 'todos') {
                $r['erros'][] = ['linha' => $item['linha'], 'mensagem' => $motivo.' Linha não importada.'];
                continue;
            }

            try {
                $tomador = Tomador::where('documento', $item['documento'])->first();
                $resultado = $this->gravar($tomador, $item['documento'], $item['dados'], $userId);
            } catch (\Throwable $e) {
                report($e);   // o detalhe técnico fica no log; para o usuário, só o que dá para agir
                $r['erros'][] = ['linha' => $item['linha'], 'mensagem' => 'Não foi possível gravar esta linha. Confira os dados dela; as demais linhas foram importadas.'];
                continue;
            }

            $r[$resultado]++;
            if ($item['erros']) {
                $r['ressalvas']++;
                $r['erros'][] = ['linha' => $item['linha'], 'mensagem' => 'Importada sem o(s) campo(s) com erro, cadastro pendente de correção: '.$motivo];
            }
        }

        return $r;
    }

    private function gravar(?Tomador $tomador, string $documento, array $dados, ?int $userId): string
    {
        $novo = $tomador === null;
        $tomador ??= new Tomador(['documento' => $documento]);

        // Mudou município ou UF: procura o código IBGE de novo
        $municipio = $dados['municipio'] ?? $tomador->municipio;
        $uf = $dados['uf'] ?? $tomador->uf;
        if (($municipio !== $tomador->municipio || $uf !== $tomador->uf || ! $tomador->codigo_municipio) && filled($municipio) && filled($uf)) {
            $dados['codigo_municipio'] = MunicipiosIbge::codigo($municipio, $uf);
        }

        $historico = [];
        foreach ($dados as $campo => $valor) {
            $antes = $tomador->{$campo};
            if ($antes === $valor || (blank($antes) && blank($valor))) {
                continue;
            }
            $historico[$campo] = [$antes, $valor];
            $tomador->{$campo} = $valor;
        }

        if ($historico === [] && ! $novo) {
            return 'sem_mudanca';
        }

        $tomador->pendente = ! $tomador->enderecoCompleto();
        $tomador->save();

        if ($historico !== []) {
            TomadorAlteracao::create([
                'conta_id' => $tomador->conta_id, 'tomador_id' => $tomador->id, 'user_id' => $userId,
                'origem' => 'planilha_clientes', 'campos' => $historico,
            ]);
        }

        return $novo ? 'criados' : 'atualizados';
    }

    /** Tamanho máximo de cada campo de texto (o mesmo das colunas do banco) e o nome que aparece no aviso. */
    private const LIMITES = [
        'nome' => [300, 'Razão Social/Nome'], 'nome_fantasia' => [300, 'Nome Fantasia'], 'logradouro' => [255, 'Logradouro'], 'numero' => [60, 'Número'],
        'complemento' => [156, 'Complemento'], 'bairro' => [156, 'Bairro'], 'municipio' => [120, 'Município'], 'observacoes' => [2000, 'Observações'],
    ];

    /**
     * Só devolve o que a linha trouxe preenchido (vazio preserva o cadastro).
     *
     * @return array{0:array<string,mixed>,1:array<string,string>} [dados válidos, erro por campo]
     */
    private function normalizar(array $l): array
    {
        $d = [];
        $erros = [];

        foreach (self::LIMITES as $c => [$max, $rotulo]) {
            if (filled($v = trim((string) ($l[$c] ?? '')))) {
                if (mb_strlen($v) > $max) {
                    $erros[$c] = "{$rotulo} passou de {$max} caracteres (tem ".mb_strlen($v).'). Encurte o texto.';
                } else {
                    $d[$c] = $v;
                }
            }
        }

        if (filled($uf = strtoupper(trim((string) ($l['uf'] ?? ''))))) {
            if (! preg_match('/^[A-Z]{2}$/', $uf)) {
                $erros['uf'] = "UF \"{$uf}\" inválida: use 2 letras (ex.: SP).";
            } else {
                $d['uf'] = $uf;
            }
        }

        if (filled($cep = preg_replace('/\D/', '', (string) ($l['cep'] ?? '')))) {
            $cep = str_pad($cep, 8, '0', STR_PAD_LEFT);
            if (strlen($cep) !== 8) {
                $erros['cep'] = 'CEP inválido: precisa ter 8 dígitos.';
            } else {
                $d['cep'] = $cep;
            }
        }

        $emails = collect(preg_split('/[;,\s]+/', (string) ($l['emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($e) => strtolower(trim($e)))->unique()->values();
        if ($emails->isNotEmpty()) {
            if ($invalido = $emails->first(fn ($e) => ! filter_var($e, FILTER_VALIDATE_EMAIL))) {
                $erros['emails'] = "E-mail inválido: {$invalido}.";
            } else {
                $d['emails'] = $emails->all();
            }
        }

        return [$d, $erros];
    }

    private function documento(string $valor): ?string
    {
        $d = preg_replace('/\D/', '', $valor);
        if ($d === '') {
            return null;
        }
        if (strlen($d) <= 11) {
            $d = str_pad($d, 11, '0', STR_PAD_LEFT);
        } elseif (strlen($d) < 14) {
            $d = str_pad($d, 14, '0', STR_PAD_LEFT);
        }

        return Documento::valido($d) ? $d : null;
    }

    /** @return array<int,array<string,string>> número da linha na planilha => campos */
    private function ler(string $caminho): array
    {
        try {
            $leitor = IOFactory::createReaderForFile($caminho);
            $leitor->setReadDataOnly(true);
            $planilha = $leitor->load($caminho);
        } catch (\Throwable) {
            throw new \RuntimeException('Não conseguimos abrir este arquivo. Use a planilha modelo de clientes (.xlsx ou .csv).');
        }

        foreach ($planilha->getAllSheets() as $aba) {
            foreach ([2, 1] as $linhaCab) {   // modelo atual: cabeçalho na linha 2 (a 1 identifica o emitente); modelo antigo: na linha 1
                if (($cab = $this->cabecalho($aba, $linhaCab)) && isset($cab['documento'])) {
                    return $this->linhas($aba, $cab, $linhaCab);
                }
            }
        }

        throw new \RuntimeException('Arquivo fora do padrão: não encontramos a coluna "CNPJ/CPF" nos cabeçalhos (linha 2). Baixe a planilha modelo de tomadores e copie os dados para ela.');
    }

    /** @return array<string,int> campo => índice da coluna */
    private function cabecalho(Worksheet $aba, int $linhaCab = 1): array
    {
        $apelidos = [
            'documento' => ['cnpjcpf', 'cpfcnpj', 'cnpj', 'cpf', 'documento'],
            'nome' => ['razaosocialnome', 'razaosocial', 'nome', 'razaosocialnomecompleto'],
            'nome_fantasia' => ['nomefantasia', 'fantasia'],
            'logradouro' => ['logradouro', 'endereco', 'rua'],
            'numero' => ['numero', 'n', 'nro'],
            'complemento' => ['complemento'],
            'bairro' => ['bairro'],
            'municipio' => ['municipio', 'cidade'],
            'uf' => ['uf', 'estado'],
            'cep' => ['cep'],
            'emails' => ['emails', 'email', 'emaildocliente'],
            'observacoes' => ['observacoes', 'observacao', 'obs'],
        ];

        $mapa = [];
        $max = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($aba->getHighestColumn());
        for ($c = 1; $c <= $max; $c++) {
            $chave = preg_replace('/[^a-z0-9]/', '', Str::of((string) $aba->getCell([$c, $linhaCab])->getValue())->ascii()->lower()->toString());
            foreach ($apelidos as $campo => $lista) {
                if (! isset($mapa[$campo]) && in_array($chave, $lista, true)) {
                    $mapa[$campo] = $c;
                }
            }
        }

        return $mapa;
    }

    private function linhas(Worksheet $aba, array $cab, int $linhaCab = 1): array
    {
        // linhas de dados = última linha menos as de cabeçalho (e a do emitente, no modelo novo)
        $ultima = $aba->getHighestRow();
        $limite = self::limite();
        $dados = 0;
        for ($r = $linhaCab + 1; $r <= $ultima; $r++) {
            foreach ($cab as $c) {
                if (trim((string) $aba->getCell([$c, $r])->getValue()) !== '') {
                    $dados++;
                    break;
                }
            }
        }
        if ($dados > $limite) {
            throw new \RuntimeException("A planilha tem {$dados} linhas e o limite é de {$limite} por importação. Divida em mais de um arquivo e importe um de cada vez.");
        }

        $saida = [];
        for ($r = $linhaCab + 1; $r <= $ultima; $r++) {
            $linha = [];
            foreach ($cab as $campo => $c) {
                $v = $aba->getCell([$c, $r])->getValue();
                $linha[$campo] = is_float($v) && floor($v) === $v ? (string) (int) $v : trim((string) $v);
            }
            if (collect($linha)->filter(fn ($v) => $v !== '')->isEmpty()) {
                continue;   // linha em branco
            }
            $saida[$r] = $linha;
        }

        return $saida;
    }
}

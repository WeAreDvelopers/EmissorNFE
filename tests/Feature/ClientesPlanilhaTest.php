<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Conta;
use App\Models\Revenda;
use App\Models\Tomador;
use App\Models\TomadorAlteracao;
use App\Models\User;
use App\Support\Tenancy\ContaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Cadastro de clientes por planilha: modelo para baixar, criação, atualização só do que veio preenchido, erros por linha. */
class ClientesPlanilhaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Conta $conta;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['servicodados.ibge.gov.br/*' => Http::response([['id' => 3550308, 'nome' => 'São Paulo'], ['id' => 3547809, 'nome' => 'Santo André']])]);

        $this->user = User::factory()->create();
        $this->conta = Conta::create(['revenda_id' => Revenda::first()->id, 'nome' => 'Moneycar', 'documento' => '11222333000181', 'email_financeiro' => 'f@x.com']);
        $this->conta->usuarios()->attach($this->user->id, ['papel' => Papel::Dono->value]);
        $this->user->forceFill(['conta_atual_id' => $this->conta->id])->save();
    }

    /** @param array<int,array<int,string>> $linhas cada linha na ordem do modelo */
    private function planilha(array $linhas, ?array $cabecalho = null): UploadedFile
    {
        $livro = new Spreadsheet();
        $aba = $livro->getActiveSheet();
        $cabecalho ??= ['CNPJ/CPF', 'Razão Social/Nome', 'Nome Fantasia', 'Logradouro', 'Número', 'Complemento', 'Bairro', 'Município', 'UF', 'CEP', 'E-mails', 'Observações'];
        foreach ($cabecalho as $i => $t) {
            $aba->setCellValue([$i + 1, 1], $t);
        }
        foreach ($linhas as $n => $l) {
            foreach ($l as $i => $v) {
                $aba->setCellValueExplicit([$i + 1, $n + 2], $v, 's');
            }
        }
        $arquivo = tempnam(sys_get_temp_dir(), 'cl').'.xlsx';
        (new Xlsx($livro))->save($arquivo);

        return new UploadedFile($arquivo, 'clientes.xlsx', null, null, true);
    }

    private function importar(UploadedFile $f)
    {
        return $this->actingAs($this->user)->post(route('tomadores.importar'), ['planilha' => $f]);
    }

    private function clientes()
    {
        return app(ContaAtual::class)->como($this->conta->id, fn () => Tomador::orderBy('id')->get());
    }

    public function test_baixa_o_modelo_com_os_cabecalhos(): void
    {
        $r = $this->actingAs($this->user)->get(route('tomadores.modelo'))->assertOk();
        $this->assertStringContainsString('modelo-clientes.xlsx', $r->headers->get('content-disposition'));
    }

    private function emitenteDaConta(): \App\Models\Emitente
    {
        return app(ContaAtual::class)->como($this->conta->id, fn () => \App\Models\Emitente::create([
            'cnpj' => '19534920000118', 'razao_social' => 'BR Controle Servicos de Gestao de Dados Ltda', 'codigo_municipio' => '3550308',
            'op_simples_nacional' => 3, 'regime_apuracao_sn' => 1, 'regime_especial' => 0, 'aliquota_simples' => 6, 'serie_dps' => '00001', 'ambiente' => 2,
        ]));
    }

    public function test_modelo_traz_cnpj_e_razao_social_do_emitente_na_primeira_linha_e_aba_de_regras_protegida(): void
    {
        $e = $this->emitenteDaConta();
        $r = $this->actingAs($this->user)->get(route('tomadores.modelo', ['emitente' => $e->id]))->assertOk();

        $arquivo = tempnam(sys_get_temp_dir(), 'mod').'.xlsx';
        file_put_contents($arquivo, $r->baseResponse->getFile()->getContent());
        $livro = \PhpOffice\PhpSpreadsheet\IOFactory::load($arquivo);
        $aba = $livro->getSheetByName('Clientes');
        $this->assertSame('19.534.920/0001-18', $aba->getCell('A1')->getValue());
        $this->assertSame('BR Controle Servicos de Gestao de Dados Ltda', $aba->getCell('B1')->getValue());
        $this->assertSame('CNPJ/CPF', $aba->getCell('A2')->getValue());
        $this->assertTrue($livro->getSheetByName('Como preencher')->getProtection()->getSheet());   // regras: só leitura
    }

    public function test_importa_planilha_no_modelo_novo_com_cabecalho_na_linha_2(): void
    {
        $livro = new Spreadsheet();
        $aba = $livro->getActiveSheet();
        $aba->setCellValue('A1', '19.534.920/0001-18')->setCellValue('B1', 'BR Controle');
        foreach (['CNPJ/CPF', 'Razão Social/Nome', 'Nome Fantasia', 'Logradouro', 'Número', 'Complemento', 'Bairro', 'Município', 'UF', 'CEP', 'E-mails', 'Observações'] as $i => $t) {
            $aba->setCellValue([$i + 1, 2], $t);
        }
        foreach (['19566539000130', 'Malix Automóveis Ltda', '', 'Rua A', '10', '', 'Centro', 'São Paulo', 'SP', '01001000', 'a@b.com', ''] as $i => $v) {
            $aba->setCellValueExplicit([$i + 1, 3], $v, 's');
        }
        $arquivo = tempnam(sys_get_temp_dir(), 'cl').'.xlsx';
        (new Xlsx($livro))->save($arquivo);

        $this->importar(new UploadedFile($arquivo, 'clientes.xlsx', null, null, true))->assertRedirect(route('tomadores.index'));
        $t = $this->clientes()->firstOrFail();
        $this->assertSame('19566539000130', $t->documento);
        $this->assertSame('Malix Automóveis Ltda', $t->nome);
    }

    public function test_tela_de_importacao_segue_o_padrao_com_painel_lateral_de_regras(): void
    {
        $this->emitenteDaConta();
        $this->actingAs($this->user)->get(route('tomadores.importar.tela'))->assertOk()
            ->assertSee('Importar tomadores')->assertSee('Modelo da planilha')->assertSee('Linha 1')->assertSee('Como preencher')
            ->assertSee('BR Controle Servicos de Gestao de Dados Ltda')->assertSee(route('tomadores.importar'), false);
        $this->get(route('tomadores.index'))->assertSee(route('tomadores.importar.tela'), false);
    }

    public function test_planilha_de_tomadores_aceita_ate_50_linhas_e_recusa_mais_que_isso(): void
    {
        $linhas = fn (int $n) => array_map(fn ($i) => [sprintf('%011d', $i), "Cliente $i", '', 'Rua A', '10', '', 'Centro', 'São Paulo', 'SP', '01001000', "c$i@x.com", ''], range(1, $n));

        $this->importar($this->planilha($linhas(51)))->assertSessionHasErrors('planilha');
        $this->assertStringContainsString('limite é de 50', session('errors')->first('planilha'));
        $this->assertSame(0, $this->clientes()->count());
    }

    public function test_campo_grande_demais_vira_erro_da_linha_e_as_outras_entram_sem_mostrar_erro_de_banco(): void
    {
        $longo = str_repeat('Serviços ', 60);   // passa de 300 caracteres
        $r = $this->importar($this->planilha([
            ['19566539000130', 'Malix Automóveis Ltda', '', 'Rua A', '10', '', 'Centro', 'São Paulo', 'SP', '01001000', 'a@b.com', ''],
            ['51613696000162', $longo, '', 'Av. Independência', '3575', '', 'Batel', 'Campinas', 'SP', '13038341', 'c@d.com', ''],
        ]));

        $r->assertRedirect(route('tomadores.index'))->assertSessionHasNoErrors();
        $resultado = session('importacao_clientes');
        $this->assertSame(1, $resultado['criados']);
        $this->assertSame(1, count($resultado['erros']));
        $this->assertStringContainsString('Razão Social/Nome passou de 300 caracteres', $resultado['erros'][0]['mensagem']);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($resultado['erros']));
        $this->assertSame(1, $this->clientes()->count());
    }

    public function test_tela_de_clientes_mostra_baixar_modelo_e_importar(): void
    {
        $this->actingAs($this->user)->get(route('tomadores.index'))->assertOk()
            ->assertSee('Baixar planilha modelo')->assertSee('Importar tomadores')->assertSee(route('tomadores.modelo'), false);
    }

    public function test_cria_clientes_novos_completos_com_codigo_ibge_e_historico(): void
    {
        $this->importar($this->planilha([
            ['11.444.777/0001-61', 'Padaria Bom Grão Ltda', 'Bom Grão', 'Rua das Flores', '120', 'Sala 2', 'Centro', 'Santo André', 'sp', '09290-030', 'a@x.com; B@x.com', 'VIP'],
        ]))->assertRedirect(route('tomadores.index'))->assertSessionHas('importacao_clientes', fn ($r) => $r['criados'] === 1 && $r['erros'] === []);

        $t = $this->clientes()->firstOrFail();
        $this->assertSame('11444777000161', $t->documento);
        $this->assertSame('3547809', $t->codigo_municipio);
        $this->assertSame('09290030', $t->cep);
        $this->assertSame('SP', $t->uf);
        $this->assertSame(['a@x.com', 'b@x.com'], $t->emails);
        $this->assertFalse($t->pendente);   // endereço completo
        $this->assertSame('planilha_clientes', app(ContaAtual::class)->como($this->conta->id, fn () => TomadorAlteracao::firstOrFail()->origem));
    }

    public function test_cliente_existente_so_muda_o_que_veio_preenchido(): void
    {
        $this->importar($this->planilha([['11.444.777/0001-61', 'Padaria Bom Grão Ltda', '', 'Rua A', '1', '', 'Centro', 'São Paulo', 'SP', '01001000', 'a@x.com', '']]));

        // segunda planilha: muda só o e-mail; o resto fica vazio e deve ser preservado
        $this->importar($this->planilha([['11444777000161', '', '', '', '', '', '', '', '', '', 'novo@x.com', '']]))
            ->assertSessionHas('importacao_clientes', fn ($r) => $r['atualizados'] === 1 && $r['criados'] === 0);

        $t = $this->clientes()->firstOrFail();
        $this->assertSame(['novo@x.com'], $t->emails);
        $this->assertSame('Padaria Bom Grão Ltda', $t->nome);
        $this->assertSame('Rua A', $t->logradouro);

        // terceira: nada muda
        $this->importar($this->planilha([['11444777000161', '', '', '', '', '', '', '', '', '', 'novo@x.com', '']]))
            ->assertSessionHas('importacao_clientes', fn ($r) => $r['sem_mudanca'] === 1);
    }

    public function test_linhas_com_problema_sao_listadas_e_as_boas_entram(): void
    {
        $this->importar($this->planilha([
            ['123', 'Doc inválido', '', '', '', '', '', '', '', '', '', ''],
            ['11444777000161', '', '', '', '', '', '', '', '', '', '', ''],                      // novo sem nome
            ['11222333000181', 'Email ruim', '', '', '', '', '', '', '', '', 'nao-e-email', ''],
            ['11444777000161', 'Boa Ltda', '', '', '', '', '', '', '', '', 'ok@x.com', ''],      // a boa
            ['11444777000161', 'Repetida', '', '', '', '', '', '', '', '', '', ''],              // repetida
        ]))->assertSessionHas('importacao_clientes', function ($r) {
            return $r['criados'] === 1 && count($r['erros']) === 4
                && $r['erros'][0]['linha'] === 2 && str_contains($r['erros'][2]['mensagem'], 'E-mail inválido');
        });

        $this->assertCount(1, $this->clientes());
    }

    public function test_zeros_comidos_pelo_excel_sao_recompostos(): void
    {
        // CNPJ 00.000.000/0001-91 chegou como número, sem os zeros da frente
        $this->importar($this->planilha([['12345', 'Zeros Ltda', '', '', '', '', '', '', '', '', '', '']]));
        $this->assertCount(0, $this->clientes());   // 5 dígitos viram CPF e não passam no dígito verificador

        $this->importar($this->planilha([['0000000000191', 'Zeros Ltda', '', '', '', '', '', '', '', '', '', '']]));
        $this->assertSame('00000000000191', $this->clientes()->first()?->documento);
    }

    public function test_arquivo_fora_do_padrao_e_barrado_com_mensagem(): void
    {
        $this->importar($this->planilha([['x']], ['Qualquer', 'Coisa']))
            ->assertSessionHasErrors('planilha');
        $this->assertCount(0, $this->clientes());
    }

    public function test_so_quem_emite_importa(): void
    {
        $leitor = User::factory()->create();
        $this->conta->usuarios()->attach($leitor->id, ['papel' => Papel::Leitura->value]);
        $leitor->forceFill(['conta_atual_id' => $this->conta->id])->save();

        $this->actingAs($leitor)->post(route('tomadores.importar'), ['planilha' => $this->planilha([])])->assertForbidden();
    }
}

<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusNota;
use App\Models\AuditoriaMaster;
use App\Models\Carteira;
use App\Models\Conta;
use App\Models\Emitente;
use App\Models\Importacao;
use App\Models\NotaFiscal;
use App\Models\Revenda;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Área master: só o administrador da plataforma entra; enxerga todos os clientes; somente leitura. */
class MasterTest extends TestCase
{
    use RefreshDatabase;

    private User $master;
    private User $cliente;
    private Conta $contaA;
    private Conta $contaB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->master = User::factory()->create(['email' => 'dono@plataforma.com']);
        $this->master->forceFill(['admin_plataforma' => true])->save();

        $this->contaA = $this->conta('Cliente Alfa', '11222333000181');
        $this->contaB = $this->conta('Cliente Beta', '11444777000161');

        $this->cliente = User::factory()->create();
        $this->contaA->usuarios()->attach($this->cliente->id, ['papel' => Papel::Dono->value]);
        $this->cliente->forceFill(['conta_atual_id' => $this->contaA->id])->save();

        // Alfa: 2 emitidas + 3 recusadas (E0312), certificado vencido, sem saldo
        $this->emitente($this->contaA, '00000000000191', 1, now()->subDay());
        $this->notas($this->contaA, StatusNota::Emitida, 2);
        $this->notas($this->contaA, StatusNota::Rejeitada, 3, ['E0312 O código de tributação nacional não está administrado pelo município']);
        // Beta: 1 recusada (E1235), homologação, com saldo
        $this->emitente($this->contaB, '00000000000272', 2, now()->addMonths(6));
        $this->notas($this->contaB, StatusNota::Rejeitada, 1, ['E1235 Falha no esquema XML do DF-e.']);
        Carteira::updateOrCreate(['conta_id' => $this->contaB->id], ['disponivel' => 40, 'reservado' => 0]);
    }

    private function conta(string $nome, string $doc): Conta
    {
        return Conta::create(['revenda_id' => Revenda::first()->id, 'nome' => $nome, 'documento' => $doc, 'email_financeiro' => strtolower(str_replace(' ', '.', $nome)).'@x.com']);
    }

    private function emitente(Conta $conta, string $cnpj, int $ambiente, $validade): Emitente
    {
        return Emitente::create([
            'conta_id' => $conta->id, 'cnpj' => $cnpj, 'razao_social' => 'Empresa '.$conta->nome, 'codigo_municipio' => '3550308',
            'op_simples_nacional' => 3, 'regime_apuracao_sn' => 1, 'aliquota_simples' => 6, 'serie_dps' => '00001',
            'ambiente' => $ambiente, 'certificado_validade' => $validade,
        ]);
    }

    private function notas(Conta $conta, StatusNota $status, int $qtd, ?array $erros = null): void
    {
        $emitente = Emitente::semIsolamento()->where('conta_id', $conta->id)->first();
        $imp = Importacao::semIsolamento()->where('conta_id', $conta->id)->first()
            ?? Importacao::create(['conta_id' => $conta->id, 'emitente_id' => $emitente->id, 'arquivo_nome' => $conta->nome.'.csv', 'arquivo_caminho' => 'x', 'status' => 'enviada']);

        for ($i = 0; $i < $qtd; $i++) {
            NotaFiscal::create([
                'conta_id' => $conta->id, 'emitente_id' => $emitente->id, 'importacao_id' => $imp->id, 'origem' => 'planilha', 'linha_planilha' => $i + 2,
                'status' => $status, 'serie_dps' => '00001', 'tomador_documento' => '19566539000130', 'tomador_nome' => 'Tomador '.$i,
                'codigo_servico' => '010302', 'descricao' => 'Serviço', 'valor' => 100, 'data_competencia' => now()->toDateString(),
                'erros' => $erros, 'emitida_em' => $status === StatusNota::Emitida ? now() : null,
            ]);
        }
    }

    // ---------- acesso ----------

    public function test_visitante_vai_para_o_login(): void
    {
        $this->get(route('master.visao'))->assertRedirect(route('login'));
    }

    public function test_cliente_comum_recebe_404_em_toda_a_area_master(): void
    {
        $this->actingAs($this->cliente);

        $this->get(route('master.visao'))->assertNotFound();
        $this->get(route('master.clientes'))->assertNotFound();
        $this->get(route('master.cliente', $this->contaB))->assertNotFound();
    }

    public function test_cliente_comum_nao_ve_o_atalho_master_no_menu(): void
    {
        $this->actingAs($this->cliente)->get(route('inicio'))->assertOk()->assertDontSee('Área master')->assertDontSee(route('master.visao'), false);
    }

    public function test_master_ve_o_atalho_no_menu(): void
    {
        $this->actingAs($this->master)->get(route('master.visao'))->assertOk()->assertSee('Voltar ao app');
    }

    // ---------- números ----------

    public function test_visao_geral_mostra_totais_de_todos_os_clientes(): void
    {
        $this->actingAs($this->master)->get(route('master.visao'))->assertOk()
            ->assertSee('Visão geral da plataforma')
            ->assertSee('E0312')->assertSee('E1235')                 // erros mais comuns
            ->assertSee('Cliente Alfa')                               // certificado vencido
            ->assertSee('Muitas recusas');
    }

    public function test_taxa_de_recusa_considera_todas_as_contas(): void
    {
        // emitidas 2; recusadas 3 + 1 = 4  =>  4 / 6 = 66,7%
        $this->actingAs($this->master)->get(route('master.visao'))->assertOk()->assertSee('66,7%');
    }

    public function test_lista_de_clientes_traz_todas_as_contas_e_busca(): void
    {
        $this->actingAs($this->master)->get(route('master.clientes'))->assertOk()
            ->assertSee('Cliente Alfa')->assertSee('Cliente Beta')->assertSee('certificado vencido');

        $this->get(route('master.clientes', ['q' => 'beta']))->assertOk()->assertSee('Cliente Beta')->assertDontSee('Cliente Alfa');
        $this->get(route('master.clientes', ['q' => '11222333000181']))->assertOk()->assertSee('Cliente Alfa')->assertDontSee('Cliente Beta');
        $this->get(route('master.clientes', ['situacao' => 'suspensa']))->assertOk()->assertSee('Nenhum cliente encontrado');
    }

    public function test_detalhe_do_cliente_mostra_empresas_usuarios_e_erros(): void
    {
        $this->actingAs($this->master)->get(route('master.cliente', $this->contaA))->assertOk()
            ->assertSee('Cliente Alfa')->assertSee('Empresa Cliente Alfa')->assertSee($this->cliente->email)
            ->assertSee('E0312')->assertSee('certificado vencido');
    }

    public function test_detalhe_de_um_cliente_nao_vaza_dados_de_outro(): void
    {
        $this->actingAs($this->master)->get(route('master.cliente', $this->contaA))->assertOk()
            ->assertDontSee('Empresa Cliente Beta')->assertDontSee('E1235');
    }

    // ---------- auditoria e somente leitura ----------

    public function test_abrir_um_cliente_fica_registrado_na_auditoria(): void
    {
        $this->actingAs($this->master)->get(route('master.cliente', $this->contaA))->assertOk();

        $this->assertDatabaseHas('auditoria_master', ['user_id' => $this->master->id, 'acao' => 'ver_conta', 'conta_id' => $this->contaA->id]);
    }

    public function test_auditoria_e_imutavel(): void
    {
        AuditoriaMaster::registrar('teste');
        $registro = AuditoriaMaster::first();

        $this->expectException(\LogicException::class);
        $registro->delete();
    }

    public function test_unica_escrita_da_area_master_e_a_cobranca_da_empresa(): void
    {
        $escritas = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->getName() ?? '', 'master.'))
            ->filter(fn ($r) => array_diff($r->methods(), ['GET', 'HEAD']))
            ->map(fn ($r) => $r->getName())->values()->all();

        $this->assertSame(['master.empresa.cobranca'], $escritas);
    }

    // ---------- cobrança por empresa ----------

    private function empresaAlfa(): Emitente
    {
        return Emitente::semIsolamento()->where('conta_id', $this->contaA->id)->first();
    }

    public function test_master_desliga_e_religa_a_cobranca_de_uma_empresa_com_auditoria(): void
    {
        $empresa = $this->empresaAlfa();
        $this->actingAs($this->master);

        $this->post(route('master.empresa.cobranca', $empresa->id), ['desligar' => 1])->assertRedirect();
        $this->assertTrue($empresa->fresh()->cobranca_desligada);
        $this->assertDatabaseHas('auditoria_master', ['acao' => 'cobranca_desligada', 'conta_id' => $this->contaA->id, 'user_id' => $this->master->id]);

        $this->post(route('master.empresa.cobranca', $empresa->id), ['desligar' => 0])->assertRedirect();
        $this->assertFalse($empresa->fresh()->cobranca_desligada);
        $this->assertDatabaseHas('auditoria_master', ['acao' => 'cobranca_ligada']);
    }

    public function test_cliente_comum_nao_consegue_desligar_a_cobranca(): void
    {
        $this->actingAs($this->cliente)->post(route('master.empresa.cobranca', $this->empresaAlfa()->id), ['desligar' => 1])->assertNotFound();
        $this->assertFalse($this->empresaAlfa()->cobranca_desligada);
    }

    public function test_cobranca_desligada_vale_so_para_a_empresa_escolhida(): void
    {
        $this->actingAs($this->master)->post(route('master.empresa.cobranca', $this->empresaAlfa()->id), ['desligar' => 1]);

        $this->assertTrue($this->empresaAlfa()->cobranca_desligada);
        $this->assertFalse(Emitente::semIsolamento()->where('conta_id', $this->contaB->id)->first()->cobranca_desligada);
    }

    public function test_empresa_sem_cobranca_nao_reserva_nem_consome_credito_em_producao(): void
    {
        $empresa = $this->empresaAlfa();                       // produção (ambiente 1), conta Alfa sem saldo
        $nota = NotaFiscal::semIsolamento()->where('emitente_id', $empresa->id)->first();
        $carteira = app(\App\Services\Creditos\CarteiraService::class);

        $this->assertFalse($carteira->reservar($nota), 'com cobrança e sem saldo, não reserva');

        $empresa->forceFill(['cobranca_desligada' => true])->save();

        $this->assertTrue($carteira->reservar($nota), 'sem cobrança, segue sem precisar de saldo');
        $this->assertDatabaseMissing('movimentos_credito', ['nota_fiscal_id' => $nota->id]);
    }

    // ---------- comando ----------

    public function test_comando_da_e_tira_o_acesso_master(): void
    {
        $this->artisan('master:definir', ['email' => $this->cliente->email])->assertSuccessful();
        $this->assertTrue((bool) $this->cliente->fresh()->admin_plataforma);

        $this->artisan('master:definir', ['email' => $this->cliente->email, '--remover' => true])->assertSuccessful();
        $this->assertFalse((bool) $this->cliente->fresh()->admin_plataforma);

        $this->artisan('master:definir', ['email' => 'ninguem@x.com'])->assertFailed();
    }
}

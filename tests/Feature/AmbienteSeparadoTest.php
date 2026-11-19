<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusNota;
use App\Models\Auditoria;
use App\Models\Conta;
use App\Models\Emitente;
use App\Models\Importacao;
use App\Models\NotaFiscal;
use App\Models\Revenda;
use App\Models\User;
use App\Support\Tenancy\ContaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Trocar a empresa de teste (homologação) para produção: Auditoria, Notas e Relatórios só mostram o ambiente em uso. */
class AmbienteSeparadoTest extends TestCase
{
    use RefreshDatabase;

    private User $dono;
    private Emitente $emitente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dono = User::factory()->create();
        $conta = Conta::create(['revenda_id' => Revenda::first()->id, 'nome' => 'Moneycar', 'documento' => '11222333000181', 'email_financeiro' => 'f@x.com']);
        $conta->usuarios()->attach($this->dono->id, ['papel' => Papel::Dono->value]);
        $this->dono->forceFill(['conta_atual_id' => $conta->id])->save();

        app(ContaAtual::class)->como($conta->id, function () {
            $this->emitente = Emitente::create([
                'cnpj' => '19534920000118', 'razao_social' => 'Moneycar Ltda', 'codigo_municipio' => '3550308', 'op_simples_nacional' => 3, 'regime_apuracao_sn' => 1,
                'regime_especial' => 0, 'aliquota_simples' => 6, 'codigo_tributacao_municipal' => '001', 'serie_dps' => '00001', 'ambiente' => 2,
                'codigo_servico_padrao' => '010302', 'descricao_servico_padrao' => 'Suporte',
            ]);
            $this->dono->forceFill(['emitente_atual_id' => $this->emitente->id])->save();
            $this->lote('lote-teste.xlsx');
            $this->nota('Cliente Teste', 100);                       // nasce em homologação
            Auditoria::registrar('nota_corrigida', 'Ação feita em teste.', [], emitenteId: $this->emitente->id);

            $this->emitente->forceFill(['ambiente' => 1])->save();    // vira produção
            $this->lote('lote-real.xlsx');
            $this->nota('Cliente Real', 250);
            Auditoria::registrar('nota_corrigida', 'Ação feita em produção.', [], emitenteId: $this->emitente->id);
        });
    }

    private function lote(string $nome): Importacao
    {
        return Importacao::create(['emitente_id' => $this->emitente->id, 'arquivo_nome' => $nome, 'arquivo_caminho' => 'importacoes/'.$nome, 'arquivo_hash' => hash('sha256', $nome), 'status' => 'concluida']);
    }

    private function nota(string $cliente, float $valor): NotaFiscal
    {
        return NotaFiscal::create([
            'emitente_id' => $this->emitente->id, 'origem' => 'manual', 'status' => StatusNota::Emitida, 'serie_dps' => '00001',
            'tomador_nome' => $cliente, 'tomador_documento' => '12345678909', 'codigo_servico' => '010302', 'descricao' => 'Suporte',
            'valor' => $valor, 'data_competencia' => now()->toDateString(), 'emitida_em' => now(),
        ]);
    }

    private function trocarPara(int $ambiente): void
    {
        $this->emitente->forceFill(['ambiente' => $ambiente])->save();
    }

    public function test_importacoes_so_mostram_o_ambiente_em_uso(): void
    {
        $this->actingAs($this->dono)->get(route('importacoes.index'))->assertSee('lote-real.xlsx')->assertDontSee('lote-teste.xlsx');

        $this->trocarPara(2);
        $this->actingAs($this->dono)->get(route('importacoes.index'))->assertSee('lote-teste.xlsx')->assertDontSee('lote-real.xlsx');
    }

    public function test_lote_nasce_no_ambiente_da_empresa(): void
    {
        $this->assertSame(2, (int) Importacao::semIsolamento()->where('arquivo_nome', 'lote-teste.xlsx')->value('ambiente'));
        $this->assertSame(1, (int) Importacao::semIsolamento()->where('arquivo_nome', 'lote-real.xlsx')->value('ambiente'));
    }

    public function test_notas_so_mostram_o_ambiente_em_uso(): void
    {
        $this->actingAs($this->dono)->get(route('notas.index'))->assertSee('Cliente Real')->assertDontSee('Cliente Teste');

        $this->trocarPara(2);
        $this->actingAs($this->dono)->get(route('notas.index'))->assertSee('Cliente Teste')->assertDontSee('Cliente Real');
    }

    public function test_relatorio_so_conta_o_ambiente_em_uso(): void
    {
        $this->actingAs($this->dono)->get(route('relatorios.index'))->assertViewHas('qtdEmitidas', 1)->assertViewHas('valorEmitido', 250.0);

        $csv = $this->actingAs($this->dono)->get(route('relatorios.csv'))->streamedContent();
        $this->assertStringContainsString('Cliente Real', $csv);
        $this->assertStringNotContainsString('Cliente Teste', $csv);

        $this->trocarPara(2);
        $this->actingAs($this->dono)->get(route('relatorios.index'))->assertViewHas('qtdEmitidas', 1)->assertViewHas('valorEmitido', 100.0);
    }

    public function test_auditoria_so_mostra_o_ambiente_em_uso(): void
    {
        $this->actingAs($this->dono)->get(route('auditoria.index'))->assertSee('Ação feita em produção.')->assertDontSee('Ação feita em teste.');

        $this->trocarPara(2);
        $this->actingAs($this->dono)->get(route('auditoria.index'))->assertSee('Ação feita em teste.')->assertDontSee('Ação feita em produção.');
    }
}

<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusNota;
use App\Models\Auditoria;
use App\Models\Conta;
use App\Models\Emitente;
use App\Models\NotaFiscal;
use App\Models\Revenda;
use App\Models\User;
use App\Support\Tenancy\ContaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Excluir só o que nunca foi emitido; editar pelo painel as notas que ainda não saíram. */
class NotaExclusaoTest extends TestCase
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
                'regime_especial' => 0, 'aliquota_simples' => 6, 'codigo_tributacao_municipal' => '001', 'serie_dps' => '00001', 'ambiente' => 1,
                'codigo_servico_padrao' => '010302', 'descricao_servico_padrao' => 'Suporte',
            ]);
            $this->dono->forceFill(['emitente_atual_id' => $this->emitente->id])->save();
        });
    }

    private function nota(StatusNota $status, array $extra = []): NotaFiscal
    {
        return app(ContaAtual::class)->como($this->emitente->conta_id, fn () => NotaFiscal::create($extra + [
            'emitente_id' => $this->emitente->id, 'origem' => 'manual', 'status' => $status, 'serie_dps' => '00001',
            'tomador_nome' => 'JR Motors', 'tomador_documento' => '39914641000197', 'codigo_servico' => '010701', 'descricao' => 'Suporte',
            'valor' => 1260, 'data_competencia' => now()->toDateString(),
        ]));
    }

    public function test_exclui_nota_manual_que_nao_saiu_e_registra_na_auditoria(): void
    {
        $nota = $this->nota(StatusNota::Falhou);   // sem número de DPS: nunca chegou à SEFIN

        $this->actingAs($this->dono)->delete(route('notas.excluir', $nota))->assertRedirect();

        $this->assertNull(NotaFiscal::semIsolamento()->find($nota->id));
        $this->assertTrue(Auditoria::where('acao', 'nota_excluida')->where('descricao', 'like', '%JR Motors%')->exists());
    }

    public function test_exclui_nota_recusada_e_dado_invalido(): void
    {
        foreach ([StatusNota::Rejeitada, StatusNota::Invalida] as $status) {
            $nota = $this->nota($status, ['numero_dps' => $status === StatusNota::Rejeitada ? 5 : null]);
            $this->actingAs($this->dono)->delete(route('notas.excluir', $nota))->assertRedirect();
            $this->assertNull(NotaFiscal::semIsolamento()->find($nota->id), $status->value);
        }
    }

    public function test_nao_saiu_com_numero_de_dps_so_depois_de_conferir(): void
    {
        $nota = $this->nota(StatusNota::Falhou, ['numero_dps' => 7]);

        $this->actingAs($this->dono)->delete(route('notas.excluir', $nota))->assertStatus(422);
        $this->assertNotNull(NotaFiscal::semIsolamento()->find($nota->id));

        NotaFiscal::semIsolamento()->whereKey($nota->id)->update(['conciliada_em' => now()]);   // "Conferir": a SEFIN não tem a nota
        $this->actingAs($this->dono)->delete(route('notas.excluir', $nota))->assertRedirect();
        $this->assertNull(NotaFiscal::semIsolamento()->find($nota->id));
    }

    public function test_emitida_e_cancelada_nunca_sao_excluidas(): void
    {
        foreach ([StatusNota::Emitida, StatusNota::Cancelada, StatusNota::Pendente, StatusNota::Enviando] as $i => $status) {
            $nota = $this->nota($status, ['numero_dps' => 20 + $i]);
            $this->actingAs($this->dono)->delete(route('notas.excluir', $nota))->assertStatus(422);
            $this->assertNotNull(NotaFiscal::semIsolamento()->find($nota->id), $status->value);
        }
    }

    public function test_lista_de_notas_so_oferece_excluir_onde_pode(): void
    {
        $this->nota(StatusNota::Rejeitada, ['tomador_nome' => 'Cliente Recusado']);
        $this->nota(StatusNota::Emitida, ['tomador_nome' => 'Cliente Emitido', 'numero_dps' => 11, 'numero_nfse' => '11']);

        $html = $this->actingAs($this->dono)->get(route('notas.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'Excluir mesmo?'));
    }

    public function test_painel_de_nota_que_so_precisa_reenviar_tambem_deixa_editar(): void
    {
        $nota = $this->nota(StatusNota::Falhou, ['numero_dps' => 3]);

        $this->actingAs($this->dono)->get(route('pendencias', ['nota' => $nota->id]))
            ->assertOk()->assertSee('Editar dados da nota')->assertSee('Reenviar');
    }
}

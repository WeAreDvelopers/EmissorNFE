<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusNota;
use App\Models\Conta;
use App\Models\Emitente;
use App\Models\NotaFiscal;
use App\Models\Revenda;
use App\Models\User;
use App\Services\Nfse\NumeracaoDps;
use App\Support\Tenancy\ContaAtual;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Numeração da DPS: contador por empresa + série + ambiente, número único por nota, ajuste só pelo dono. */
class NumeracaoDpsTest extends TestCase
{
    use RefreshDatabase;

    private User $dono;
    private Conta $conta;
    private Emitente $emitente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dono = User::factory()->create();
        $this->conta = Conta::create(['revenda_id' => Revenda::first()->id, 'nome' => 'Moneycar', 'documento' => '11222333000181', 'email_financeiro' => 'f@x.com']);
        $this->conta->usuarios()->attach($this->dono->id, ['papel' => Papel::Dono->value]);
        $this->dono->forceFill(['conta_atual_id' => $this->conta->id])->save();

        app(ContaAtual::class)->como($this->conta->id, function () {
            $this->emitente = Emitente::create([
                'cnpj' => '19534920000118', 'razao_social' => 'Moneycar Ltda', 'codigo_municipio' => '3550308',
                'op_simples_nacional' => 3, 'regime_apuracao_sn' => 1, 'regime_especial' => 0, 'aliquota_simples' => 6, 'serie_dps' => '00001', 'ambiente' => 2,
            ]);
        });
    }

    private function nota(?Emitente $e = null): NotaFiscal
    {
        $e ??= $this->emitente;

        return NotaFiscal::create([
            'conta_id' => $this->conta->id, 'emitente_id' => $e->id, 'origem' => 'planilha', 'status' => StatusNota::Pendente,
            'serie_dps' => $e->serie_dps, 'tomador_documento' => '52998224725', 'tomador_nome' => 'Cliente', 'codigo_servico' => '010302',
            'descricao' => 'x', 'valor' => 10, 'data_competencia' => now()->toDateString(),
        ]);
    }

    public function test_numeros_sequenciais_e_sempre_o_mesmo_no_reenvio(): void
    {
        $a = $this->nota();
        $b = $this->nota();

        $this->assertSame(1, $a->reservarNumeroDps());
        $this->assertSame(2, $b->reservarNumeroDps());
        $this->assertSame(1, $a->fresh()->reservarNumeroDps(), 'reenvio ou falha de rede nunca gera outra identificação');
        $this->assertSame(1, $a->fresh()->numero_dps);
        $this->assertSame(2, (int) $a->fresh()->ambiente);
    }

    public function test_teste_e_producao_tem_contadores_separados(): void
    {
        $this->assertSame(1, $this->nota()->reservarNumeroDps());
        $this->assertSame(2, $this->nota()->reservarNumeroDps());

        $this->emitente->update(['ambiente' => 1]);

        $producao = $this->nota();
        $this->assertSame(1, $producao->reservarNumeroDps(), 'a produção começa do 1, sem gastar números do teste');
        $this->assertSame(1, (int) $producao->fresh()->ambiente);
    }

    public function test_series_diferentes_tem_contadores_proprios(): void
    {
        $this->nota()->reservarNumeroDps();
        $this->emitente->update(['serie_dps' => '00002']);

        $this->assertSame(1, $this->nota()->reservarNumeroDps());
    }

    public function test_empresas_diferentes_nao_dividem_contador(): void
    {
        $outra = app(ContaAtual::class)->como($this->conta->id, fn () => Emitente::create([
            'cnpj' => '11444777000161', 'razao_social' => 'Outra', 'codigo_municipio' => '3550308', 'op_simples_nacional' => 3,
            'regime_apuracao_sn' => 1, 'aliquota_simples' => 6, 'serie_dps' => '00001', 'ambiente' => 2,
        ]));

        $this->nota()->reservarNumeroDps();
        $this->assertSame(1, $this->nota($outra)->reservarNumeroDps());
    }

    public function test_o_banco_barra_numero_repetido_no_mesmo_ambiente(): void
    {
        $a = $this->nota();
        $a->reservarNumeroDps();
        $b = $this->nota();

        $this->expectException(QueryException::class);
        $b->forceFill(['numero_dps' => 1, 'ambiente' => 2])->save();
    }

    public function test_contador_existente_parte_do_maior_numero_ja_usado(): void
    {
        $velha = $this->nota();
        $velha->forceFill(['numero_dps' => 40, 'ambiente' => 2])->save();   // nota antiga, sem linha de contador

        $this->assertSame(41, $this->nota()->reservarNumeroDps());
    }

    // ---------- ajuste manual ----------

    public function test_dono_ajusta_o_proximo_numero_e_fica_registrado(): void
    {
        $this->nota()->reservarNumeroDps();   // usou o 1

        $this->actingAs($this->dono)->post(route('emitentes.numeracao', $this->emitente), ['ambiente' => 2, 'proximo' => 100])->assertSessionHasNoErrors();

        $this->assertSame(100, $this->nota()->reservarNumeroDps());
        $this->assertDatabaseHas('ajustes_numeracao_dps', ['emitente_id' => $this->emitente->id, 'ambiente' => 2, 'de' => 2, 'para' => 100, 'user_id' => $this->dono->id]);

        $this->get(route('emitentes.editar', $this->emitente))->assertOk()->assertSee('Numeração da DPS')->assertSee('Ajustes manuais');
    }

    public function test_nao_deixa_voltar_para_numero_ja_usado(): void
    {
        foreach (range(1, 3) as $_) {
            $this->nota()->reservarNumeroDps();
        }

        $this->actingAs($this->dono)->post(route('emitentes.numeracao', $this->emitente), ['ambiente' => 2, 'proximo' => 2])->assertSessionHasErrors('proximo');
        $this->assertSame(4, $this->nota()->reservarNumeroDps());
    }

    public function test_so_o_dono_ajusta(): void
    {
        $operador = User::factory()->create();
        $this->conta->usuarios()->attach($operador->id, ['papel' => Papel::Operador->value]);
        $operador->forceFill(['conta_atual_id' => $this->conta->id])->save();

        $this->actingAs($operador)->post(route('emitentes.numeracao', $this->emitente), ['ambiente' => 2, 'proximo' => 50])->assertForbidden();
        $this->assertSame(1, $this->nota()->reservarNumeroDps());
    }

    public function test_tela_mostra_ultimo_proximo_e_historico(): void
    {
        $this->nota()->reservarNumeroDps();
        $this->nota()->reservarNumeroDps();

        $this->actingAs($this->dono)->get(route('emitentes.editar', $this->emitente))->assertOk()
            ->assertSee('Teste')->assertSee('Produção')->assertSee('DPS 2')->assertSee('DPS 1');
        $this->assertSame(3, app(NumeracaoDps::class)->situacao($this->emitente)[2]['proximo']);
        $this->assertSame(1, app(NumeracaoDps::class)->situacao($this->emitente)[1]['proximo']);
    }
}

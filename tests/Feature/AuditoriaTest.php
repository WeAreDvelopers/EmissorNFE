<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusNota;
use App\Jobs\EmitirNfseJob;
use App\Models\Auditoria;
use App\Models\Conta;
use App\Models\Emitente;
use App\Models\Importacao;
use App\Models\NotaFiscal;
use App\Models\Revenda;
use App\Models\User;
use App\Support\Tenancy\ContaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GeraPlanilha;
use Tests\TestCase;

/** Auditoria ampliada: mudanças fiscais, envios, correções, exclusões e usuários ficam registrados; só o dono vê. */
class AuditoriaTest extends TestCase
{
    use RefreshDatabase;

    private User $dono;
    private Conta $conta;
    private Emitente $emitente;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake([EmitirNfseJob::class]);

        $this->dono = User::factory()->create(['name' => 'Rafael']);
        $this->conta = Conta::create(['revenda_id' => Revenda::first()->id, 'nome' => 'Moneycar', 'documento' => '11222333000181', 'email_financeiro' => 'f@x.com']);
        $this->conta->usuarios()->attach($this->dono->id, ['papel' => Papel::Dono->value]);
        $this->dono->forceFill(['conta_atual_id' => $this->conta->id])->save();

        app(ContaAtual::class)->como($this->conta->id, function () {
            $this->emitente = Emitente::create([
                'cnpj' => '19534920000118', 'razao_social' => 'Moneycar Ltda', 'codigo_municipio' => '3550308', 'op_simples_nacional' => 3, 'regime_apuracao_sn' => 1,
                'regime_especial' => 0, 'aliquota_simples' => 6, 'codigo_tributacao_municipal' => '001', 'serie_dps' => '00001', 'ambiente' => 2, 'codigo_servico_padrao' => '010302', 'descricao_servico_padrao' => 'Suporte',
            ]);
        });
    }

    private function dadosEmpresa(array $extra = []): array
    {
        return $extra + [
            'cnpj' => '19534920000118', 'razao_social' => 'Moneycar Ltda', 'codigo_municipio' => '3550308', 'op_simples_nacional' => 3, 'regime_apuracao_sn' => 1, 'codigo_tributacao_municipal' => '001',
            'regime_especial' => 0, 'aliquota_simples' => 6, 'serie_dps' => '00001', 'ambiente' => 2, 'codigo_servico_padrao' => '010302', 'descricao_servico_padrao' => 'Suporte',
        ];
    }

    private function importar(array $linhas)
    {
        $this->actingAs($this->dono)->post(route('importacoes.store'), ['emitente_id' => $this->emitente->id, 'planilha' => GeraPlanilha::xlsx($this->emitente->cnpj, $linhas)])->assertSessionHasNoErrors();

        return Importacao::latest('id')->firstOrFail();
    }

    private function ultima(string $acao): Auditoria
    {
        return Auditoria::where('acao', $acao)->latest('id')->firstOrFail();
    }

    public function test_alterar_a_empresa_registra_o_antes_e_o_depois_sem_segredos(): void
    {
        $this->actingAs($this->dono)->put(route('emitentes.update', $this->emitente), $this->dadosEmpresa(['codigo_servico_padrao' => '170302', 'aliquota_simples' => 7.5, 'serie_dps' => '00002']))
            ->assertSessionHasNoErrors();

        $r = $this->ultima('empresa_alterada');
        $this->assertSame($this->dono->id, $r->user_id);
        $this->assertSame(['de' => '010302', 'para' => '170302'], $r->detalhes['codigo_servico_padrao']);
        $this->assertSame('00001', $r->detalhes['serie_dps']['de']);
        $this->assertSame('00002', $r->detalhes['serie_dps']['para']);
        $this->assertArrayNotHasKey('certificado_senha', $r->detalhes);
        $this->assertArrayNotHasKey('ambiente', $r->detalhes, 'campo que não mudou não entra');
    }

    public function test_salvar_sem_mudar_nada_nao_gera_registro(): void
    {
        $this->actingAs($this->dono)->put(route('emitentes.update', $this->emitente), $this->dadosEmpresa())->assertSessionHasNoErrors();

        $this->assertSame(0, Auditoria::where('acao', 'empresa_alterada')->count());
    }

    public function test_ajuste_de_numeracao_fica_registrado(): void
    {
        $this->actingAs($this->dono)->post(route('emitentes.numeracao', $this->emitente), ['ambiente' => 2, 'proximo' => 50])->assertSessionHasNoErrors();

        $r = $this->ultima('numeracao_ajustada');
        $this->assertStringContainsString('50', $r->descricao);
        $this->assertSame(50, $r->detalhes['proximo']);
    }

    public function test_envio_correcao_e_exclusao_de_lote_ficam_registrados(): void
    {
        $imp = $this->importar([
            ['doc' => '52998224725', 'nome_ref' => 'A', 'valor' => 100],
            ['doc' => '12345', 'nome_ref' => 'Invalida', 'valor' => 50],
        ]);
        $invalida = $imp->notas()->where('status', StatusNota::Invalida)->firstOrFail();

        $this->patch(route('notas.corrigir', $invalida), ['tomador_documento' => '11444777000161'])->assertRedirect();
        $r = $this->ultima('nota_corrigida');
        $this->assertSame('11444777000161', $r->detalhes['tomador_documento']['para']);

        $this->post(route('importacoes.enviar', $imp), ['modo' => 'enviar_prontas'])->assertRedirect();
        $this->assertSame('teste', $this->ultima('importacao_enviada')->detalhes['ambiente']);

        $outra = $this->importar([['doc' => '39053344705', 'nome_ref' => 'B', 'valor' => 77]]);
        $this->delete(route('notas.excluir', $outra->notas()->firstOrFail()))->assertRedirect();
        $this->assertSame('77.00', $this->ultima('nota_excluida')->detalhes['valor']);

        $this->delete(route('importacoes.excluir', $outra))->assertRedirect();
        $this->assertSame($outra->id, $this->ultima('importacao_excluida')->detalhes['lote']);
    }

    public function test_decisao_sobre_duplicidade_fica_registrada(): void
    {
        $this->importar([['doc' => '52998224725', 'nome_ref' => 'A', 'valor' => 100]]);
        $this->post(route('importacoes.enviar', Importacao::firstOrFail()), ['modo' => 'enviar_prontas']);
        $segunda = $this->importar([['doc' => '52998224725', 'nome_ref' => 'Outro arquivo', 'valor' => 100]]);

        $this->post(route('notas.duplicidade.aprovar', $segunda->notas()->firstOrFail()))->assertRedirect();
        $this->assertNotNull($this->ultima('duplicidade_aprovada')->detalhes['parecida_com']);
    }

    public function test_usuarios_convidados_papel_e_remocao_ficam_registrados(): void
    {
        $outro = User::factory()->create();
        $this->conta->usuarios()->attach($outro->id, ['papel' => Papel::Operador->value]);

        $this->actingAs($this->dono)->post(route('usuarios.convidar'), ['email' => 'novo@x.com', 'papel' => 'leitura'])->assertSessionHasNoErrors();
        $this->assertSame('novo@x.com', $this->ultima('usuario_convidado')->detalhes['email']);

        $this->patch(route('usuarios.papel', $outro->id), ['papel' => 'financeiro'])->assertSessionHasNoErrors();
        $this->assertSame(['de' => 'operador', 'para' => 'financeiro'], $this->ultima('papel_alterado')->detalhes['papel']);

        $this->delete(route('usuarios.remover', $outro->id))->assertSessionHasNoErrors();
        $this->assertSame($outro->id, $this->ultima('usuario_removido')->detalhes['usuario']);
    }

    // ---------- a tela ----------

    public function test_so_o_dono_ve_a_auditoria_e_so_a_da_propria_conta(): void
    {
        Auditoria::registrar('empresa_alterada', 'Mudança visível do dono', ['serie_dps' => ['de' => '1', 'para' => '2']], $this->conta->id);
        $outra = Conta::create(['revenda_id' => Revenda::first()->id, 'nome' => 'Profitcar', 'documento' => '11444777000161', 'email_financeiro' => 'p@x.com']);
        Auditoria::registrar('empresa_alterada', 'Mudança de outra conta', [], $outra->id);

        $this->actingAs($this->dono)->get(route('auditoria.index'))->assertOk()
            ->assertSee('Mudança visível do dono')->assertDontSee('Mudança de outra conta')->assertSee('Empresa alterada');

        $operador = User::factory()->create();
        $this->conta->usuarios()->attach($operador->id, ['papel' => Papel::Operador->value]);
        $operador->forceFill(['conta_atual_id' => $this->conta->id])->save();
        $this->actingAs($operador)->get(route('auditoria.index'))->assertForbidden();
    }

    public function test_filtro_por_acao_e_texto(): void
    {
        Auditoria::registrar('empresa_alterada', 'Alteração A', [], $this->conta->id);
        Auditoria::registrar('nota_excluida', 'Exclusão B', [], $this->conta->id);

        $this->actingAs($this->dono)->get(route('auditoria.index', ['acao' => 'nota_excluida']))->assertOk()->assertSee('Exclusão B')->assertDontSee('Alteração A');
        $this->get(route('auditoria.index', ['q' => 'Alteração']))->assertOk()->assertSee('Alteração A')->assertDontSee('Exclusão B');
    }

    public function test_registro_e_imutavel(): void
    {
        Auditoria::registrar('empresa_alterada', 'x', [], $this->conta->id);

        $this->expectException(\LogicException::class);
        Auditoria::semIsolamento()->firstOrFail()->delete();
    }
}

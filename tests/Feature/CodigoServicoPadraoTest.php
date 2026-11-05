<?php

namespace Tests\Feature;

use App\Models\Emitente;
use App\Support\ListaServicoNacional;
use App\Support\ValidadorLinha;
use Tests\TestCase;

class CodigoServicoPadraoTest extends TestCase
{
    private function linha(array $extra = []): array
    {
        return $extra + [
            'tomador_documento' => '19566539000130', 'tomador_nome' => 'Malix Automóveis Ltda – EPP',
            'tomador_email' => '', 'codigo_servico' => '', 'codigo_nbs' => '',
            'descricao' => 'Manutenção de programas de computação.', 'valor' => '452,50',
            'data_competencia' => '05/10/2026',
        ];
    }

    private function emitente(?string $padrao): Emitente
    {
        return new Emitente(['cnpj' => '19534920000118', 'codigo_servico_padrao' => $padrao]);
    }

    public function test_planilha_sem_codigo_usa_o_padrao_da_empresa(): void
    {
        $emit = $this->emitente('010701');
        $dados = ValidadorLinha::normalizar($this->linha(), $emit);

        $this->assertSame('010701', $dados['codigo_servico']);
        $this->assertFalse(ValidadorLinha::validar($dados, $emit)->fails());
    }

    public function test_codigo_informado_na_planilha_vence_o_padrao(): void
    {
        $dados = ValidadorLinha::normalizar($this->linha(['codigo_servico' => '17.01.01']), $this->emitente('010701'));

        $this->assertSame('170101', $dados['codigo_servico']);
    }

    public function test_codigo_sem_zero_inicial_e_completado(): void
    {
        $dados = ValidadorLinha::normalizar($this->linha(['codigo_servico' => '10701']), $this->emitente(null));

        $this->assertSame('010701', $dados['codigo_servico']);
    }

    public function test_sem_padrao_e_sem_codigo_a_linha_e_invalida(): void
    {
        $emit = $this->emitente(null);
        $v = ValidadorLinha::validar(ValidadorLinha::normalizar($this->linha(), $emit), $emit);

        $this->assertTrue($v->errors()->has('codigo_servico'));
    }

    public function test_codigo_fora_da_lista_nacional_e_barrado_antes_do_envio(): void
    {
        $emit = $this->emitente('010701');
        $v = ValidadorLinha::validar(ValidadorLinha::normalizar($this->linha(['codigo_servico' => '002919']), $emit), $emit);

        $this->assertStringContainsString('não existe na lista nacional', $v->errors()->first('codigo_servico'));
    }

    public function test_linha_totalmente_vazia_nao_recebe_o_padrao(): void
    {
        $vazia = array_fill_keys(ValidadorLinha::CAMPOS, '');
        $dados = ValidadorLinha::normalizar($vazia, $this->emitente('010701'));

        $this->assertSame('000000', $dados['codigo_servico']);
    }

    public function test_lista_nacional_tem_os_codigos_conhecidos(): void
    {
        $this->assertTrue(ListaServicoNacional::existe('010701'));
        $this->assertTrue(ListaServicoNacional::existe('170101'));
        $this->assertFalse(ListaServicoNacional::existe('002919'));
        $this->assertGreaterThan(300, count(ListaServicoNacional::todos()));
    }
}

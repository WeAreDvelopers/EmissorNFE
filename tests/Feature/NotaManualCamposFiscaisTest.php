<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Jobs\EmitirNfseJob;
use App\Models\Conta;
use App\Models\Emitente;
use App\Models\NotaFiscal;
use App\Models\Revenda;
use App\Models\Tomador;
use App\Models\User;
use App\Services\Nfse\DpsBuilder;
use App\Services\Nfse\ValidadorXsd;
use App\Support\Tenancy\ContaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Todos os campos das telas do Emissor Nacional visíveis na empresa e na emissão manual,
 * com o botão "Carregar todos os campos da nota" para ajustar só uma nota.
 */
class NotaManualCamposFiscaisTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Conta $conta;
    private Emitente $emitente;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([EmitirNfseJob::class]);
        Http::fake([
            'servicodados.ibge.gov.br/api/v1/localidades/municipios/*' => Http::response(['id' => 3550308, 'nome' => 'São Paulo', 'microrregiao' => ['mesorregiao' => ['UF' => ['sigla' => 'SP']]]]),
            'servicodados.ibge.gov.br/api/v1/localidades/estados/RJ/municipios' => Http::response([['id' => 3304557, 'nome' => 'Rio de Janeiro']]),
            'servicodados.ibge.gov.br/*' => Http::response([['id' => 3550308, 'nome' => 'São Paulo']]),
        ]);

        $this->user = User::factory()->create();
        $this->conta = Conta::create(['revenda_id' => Revenda::first()->id, 'nome' => 'Moneycar', 'documento' => '11222333000181', 'email_financeiro' => 'f@x.com']);
        $this->conta->usuarios()->attach($this->user->id, ['papel' => Papel::Dono->value]);
        $this->user->forceFill(['conta_atual_id' => $this->conta->id])->save();
        $this->actingAs($this->user);
        $this->emitente = $this->empresa();
    }

    private function empresa(array $extra = []): Emitente
    {
        return app(ContaAtual::class)->como($this->conta->id, fn () => Emitente::create($extra + [
            'cnpj' => '19534920000118'.'', 'razao_social' => 'BR Controle Ltda', 'codigo_municipio' => '3550308',
            'op_simples_nacional' => 1, 'regime_apuracao_sn' => 1, 'regime_especial' => 0, 'serie_dps' => '00001', 'informar_serie_dps' => '1', 'ambiente' => 2,
            'codigo_servico_padrao' => '010701', 'descricao_servico_padrao' => 'Manutenção de programas de computação.', 'codigo_nbs_padrao' => '115080000',
            'codigo_tributacao_municipal' => '001', 'tributos_aprox' => ['federal' => 13.45, 'estadual' => 0, 'municipal' => 2.70],
        ]));
    }

    private function dados(array $extra = []): array
    {
        return $extra + [
            'emitente_id' => $this->emitente->id,
            'tomador_documento' => '19566539000130', 'tomador_nome' => 'Malix Automóveis Ltda', 'tomador_email' => 'fin@malix.com.br',
            'codigo_servico' => '010701', 'descricao' => 'Manutenção de programas de computação.', 'codigo_nbs' => '115080000',
            'valor' => '452,50', 'data_competencia' => now()->toDateString(),
        ];
    }

    private function nota(array $ajustes): NotaFiscal
    {
        $n = new NotaFiscal([
            'serie_dps' => '00001', 'numero_dps' => 2, 'tomador_documento' => '19566539000130', 'tomador_nome' => 'Malix',
            'codigo_servico' => '010701', 'descricao' => 'Teste', 'valor' => 10, 'data_competencia' => '2026-11-05', 'parametros_dps' => $ajustes ?: null,
        ]);
        $n->setRelation('emitente', $this->emitente);

        return $n;
    }

    public function test_empresa_mostra_todos_os_campos_das_telas_do_emissor_nacional(): void
    {
        $html = $this->get(route('emitentes.editar', $this->emitente))->assertOk()->getContent();

        foreach ([
            'Ver todos os campos que vão na nota', 'País', 'Município da prestação do serviço', 'Código de tributação nacional', 'Código complementar municipal',
            'Imunidade, exportação ou não incidência do ISSQN?', 'Município de incidência do ISSQN', 'Item da NBS', 'Código indicador da operação', 'Descrição do serviço',
            'Tributação do ISSQN sobre o serviço prestado', 'Regime especial de tributação', 'Exigibilidade do ISSQN suspensa?', 'Retenção do ISSQN pelo tomador ou intermediário?',
            'Serviço amparado por benefício municipal?', 'Dedução/redução da base de cálculo do ISSQN?',
            'Situação tributária do PIS/COFINS', 'Tipo de retenção do PIS/COFINS/CSLL', 'IRRF', 'Contribuições sociais retidas', 'Contribuição previdenciária retida',
            'Código de situação tributária', 'Código de classificação tributária',
            'Valor aproximado dos tributos', 'Configurar os valores percentuais correspondentes', 'Federal', 'Estadual', 'Municipal',
            'Série da DPS', 'Ambiente',
            '13,45%', '2,70%', 'São Paulo/SP', '01.07.01', '00 - Nenhum', 'Operação tributável',
        ] as $texto) {
            $this->assertStringContainsString($texto, $html, "falta na tela da empresa: {$texto}");
        }
    }

    public function test_resumo_segue_o_regime_da_empresa(): void
    {
        $simples = $this->empresa(['cnpj' => '11222333000181', 'op_simples_nacional' => 3, 'aliquota_simples' => 6, 'tributos_aprox' => null]);
        $mei = $this->empresa(['cnpj' => '45723174000110', 'op_simples_nacional' => 2, 'tributos_aprox' => null]);

        $this->assertStringContainsString('Alíquota do Simples Nacional', json_encode($simples->camposFiscais(), JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('00 - Nenhum', json_encode($simples->camposFiscais(), JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('Não informar (MEI)', json_encode($mei->camposFiscais(), JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('não se aplica ao MEI', json_encode($mei->camposFiscais(), JSON_UNESCAPED_UNICODE));
    }

    public function test_emissao_manual_ja_vem_com_os_dados_da_empresa_e_o_botao_de_todos_os_campos(): void
    {
        $html = $this->get(route('notas.manual'))->assertOk()->getContent();

        foreach (['Nota fiscal completa personalizada', 'Voltar à nota simples', 'BR Controle Ltda', '00 - Nenhum', 'ajustes[cod_trib_mun]', 'ajustes[retencao]', 'ajustes[trib_federal]'] as $texto) {
            $this->assertStringContainsString($texto, $html, $texto);
        }
        $this->assertStringNotContainsString('Como a nota vai sair', $html);   // a tela simples não traz resumo: só o botão
        $this->assertStringContainsString('010701', $html);                 // serviço da empresa já aplicado
        $this->assertStringContainsString(trim(json_encode('Manutenção de programas de computação.'), '"'), $html);   // vai no JS da tela, com acentos escapados
    }

    public function test_sem_o_botao_a_nota_sai_so_com_os_padroes_da_empresa(): void
    {
        $this->post(route('notas.manual.store'), $this->dados(['confirmo' => '1']))->assertRedirect(route('notas.index'));

        $this->assertNull(NotaFiscal::firstOrFail()->parametros_dps);
    }

    /** O que a tela envia quando a nota completa está aberta (empresa não optante, tudo igual ao padrão). */
    private function aj(array $extra = []): array
    {
        return $extra + [
            'mun_prestacao' => '', 'uf_prestacao' => '', 'cod_trib_mun' => '001',
            'trib_issqn' => '1', 'tp_imunidade' => '', 'regime_especial' => '0', 'retencao' => '1', 'deducao_tipo' => 'none', 'deducao_valor' => '',
            'pis_cst' => '00', 'pis_ret' => '0', 'pis_bc' => '', 'pis_aliq_pis' => '', 'pis_aliq_cofins' => '', 'ret_irrf' => '', 'ret_csll' => '', 'ret_cp' => '',
            'trib_federal' => '13,45', 'trib_estadual' => '0', 'trib_municipal' => '2,70', 'aliquota_simples' => '',
        ];
    }

    private function conferir(array $ajustes)
    {
        return $this->post(route('notas.manual.conferir'), $this->dados(['avancado' => '1', 'ajustes' => $ajustes]));
    }

    private function emitirCom(array $ajustes)
    {
        return $this->post(route('notas.manual.store'), $this->dados(['avancado' => '1', 'ajustes' => $ajustes, 'confirmo' => '1']));
    }

    public function test_ajustes_aparecem_na_conferencia_e_so_o_que_mudou_e_gravado(): void
    {
        $ajustes = $this->aj(['cod_trib_mun' => '002', 'trib_federal' => '10,50', 'trib_municipal' => '2,7']);

        $this->conferir($ajustes)->assertOk()->assertSee('Diferentes do padrão da empresa, só nesta nota')
            ->assertSee('Código complementar municipal')->assertSee('10,50%')
            ->assertSee('name="ajustes[cod_trib_mun]"', false)->assertDontSee('Regime especial de tributação:');

        $this->emitirCom($ajustes)->assertRedirect(route('notas.index'));
        $this->assertEquals(['codigo_tributacao_municipal' => '002', 'tributos_aprox' => ['federal' => 10.5, 'estadual' => 0.0, 'municipal' => 2.7]], NotaFiscal::firstOrFail()->parametros_dps);
        $this->assertSame('001', $this->emitente->fresh()->codigo_tributacao_municipal);   // o cadastro da empresa não muda
    }

    public function test_botao_aberto_sem_nenhuma_mudanca_nao_grava_ajustes(): void
    {
        $this->emitirCom($this->aj(['mun_prestacao' => 'São Paulo', 'uf_prestacao' => 'SP']))->assertRedirect(route('notas.index'));

        $this->assertNull(NotaFiscal::firstOrFail()->parametros_dps);
    }

    public function test_valores_invalidos_voltam_ao_formulario_com_o_botao_aberto(): void
    {
        $r = $this->conferir($this->aj(['cod_trib_mun' => '12345', 'trib_federal' => '150', 'trib_estadual' => 'abc']));

        $r->assertRedirect(route('notas.manual'))->assertSessionHasErrors(['ajustes.cod_trib_mun', 'ajustes.trib_federal', 'ajustes.trib_estadual']);
        $this->get(route('notas.manual'))->assertSee('avancado: true', false)->assertSee('Informe um percentual entre 0 e 100.');
    }

    public function test_retencao_pelo_tomador_exige_cliente_com_endereco_completo(): void
    {
        $this->conferir($this->aj(['retencao' => '2']))->assertSessionHasErrors('ajustes.retencao');

        app(ContaAtual::class)->como($this->conta->id, fn () => Tomador::create([
            'documento' => '19566539000130', 'nome' => 'Malix Automóveis Ltda', 'logradouro' => 'Avenida Paulista', 'numero' => '1578', 'bairro' => 'Bela Vista',
            'cep' => '01310100', 'municipio' => 'São Paulo', 'uf' => 'SP', 'codigo_municipio' => '3550308',
        ]));
        $this->emitirCom($this->aj(['retencao' => '2']))->assertRedirect(route('notas.index'));

        $this->assertSame(['retencao_issqn' => 2], NotaFiscal::firstOrFail()->parametros_dps);
    }

    public function test_retencao_nao_vale_para_mei_nem_para_regime_especial(): void
    {
        $this->emitente = $this->empresa(['cnpj' => '45723174000110', 'op_simples_nacional' => 2, 'tributos_aprox' => null]);
        $this->conferir($this->aj(['retencao' => '2']))->assertSessionHasErrors('ajustes.retencao');

        $this->emitente = $this->empresa(['cnpj' => '07445371000126']);
        $this->conferir($this->aj(['regime_especial' => '3', 'retencao' => '2']))->assertSessionHasErrors('ajustes.retencao');
    }

    public function test_municipio_da_prestacao_diferente_e_reconhecido_ou_recusado(): void
    {
        $this->conferir($this->aj(['mun_prestacao' => 'Rio de Janeiro', 'uf_prestacao' => 'rj']))
            ->assertOk()->assertSee('Município da prestação')->assertSee('Rio de Janeiro/RJ');

        $this->conferir($this->aj(['mun_prestacao' => 'Cidadelandia', 'uf_prestacao' => 'RJ']))->assertSessionHasErrors('ajustes.mun_prestacao');
    }

    public function test_imunidade_e_nao_incidencia(): void
    {
        $this->conferir($this->aj(['trib_issqn' => '2']))->assertSessionHasErrors('ajustes.tp_imunidade');                          // imunidade pede o tipo (E0592)
        $this->conferir($this->aj(['trib_issqn' => '2', 'tp_imunidade' => '2', 'regime_especial' => '3']))->assertSessionHasErrors('ajustes.trib_issqn');   // E0172
        $this->conferir($this->aj(['trib_issqn' => '4', 'retencao' => '2']))->assertSessionHasErrors('ajustes.retencao');           // E0580
        $this->conferir($this->aj(['trib_issqn' => '4', 'deducao_tipo' => 'percentual', 'deducao_valor' => '10']))->assertSessionHasErrors('ajustes.deducao_tipo');   // E0435
        $this->conferir($this->aj(['trib_issqn' => '3']))->assertSessionHasErrors('ajustes.trib_issqn');                           // exportação não disponível

        $this->conferir($this->aj(['trib_issqn' => '2', 'tp_imunidade' => '2']))->assertOk()->assertSee('Templos de qualquer culto');
        $this->emitirCom($this->aj(['trib_issqn' => '2', 'tp_imunidade' => '2']));
        $this->assertSame(['trib_issqn' => 2, 'tp_imunidade' => 2], NotaFiscal::firstOrFail()->parametros_dps);

        NotaFiscal::query()->delete();
        $this->emitirCom($this->aj(['trib_issqn' => '4']));
        $this->assertSame(['trib_issqn' => 4], NotaFiscal::firstOrFail()->parametros_dps);
    }

    public function test_deducao_da_base_de_calculo(): void
    {
        $this->conferir($this->aj(['deducao_tipo' => 'percentual', 'deducao_valor' => '150']))->assertSessionHasErrors('ajustes.deducao_valor');
        $this->conferir($this->aj(['deducao_tipo' => 'valor', 'deducao_valor' => '900']))->assertSessionHasErrors('ajustes.deducao_valor');   // maior que o serviço
        $this->conferir($this->aj(['deducao_tipo' => 'percentual', 'deducao_valor' => '']))->assertSessionHasErrors('ajustes.deducao_valor');
        $this->conferir($this->aj(['deducao_tipo' => 'percentual', 'deducao_valor' => '10', 'regime_especial' => '3']))->assertSessionHasErrors('ajustes.deducao_tipo');   // E0438

        $this->conferir($this->aj(['deducao_tipo' => 'percentual', 'deducao_valor' => '10,5']))->assertOk()->assertSee('10,50%');
        $this->emitirCom($this->aj(['deducao_tipo' => 'percentual', 'deducao_valor' => '10,5']));
        $this->assertEquals(['deducao' => ['tipo' => 'percentual', 'valor' => 10.5]], NotaFiscal::firstOrFail()->parametros_dps);
    }

    public function test_deducao_nao_vale_para_mei_nem_para_simples_apurado_pelo_simples(): void
    {
        $this->emitente = $this->empresa(['cnpj' => '45723174000110', 'op_simples_nacional' => 2, 'tributos_aprox' => null]);
        $this->conferir($this->aj(['deducao_tipo' => 'valor', 'deducao_valor' => '10']))->assertSessionHasErrors('ajustes.deducao_tipo');            // E0436

        $this->emitente = $this->empresa(['cnpj' => '07445371000126', 'op_simples_nacional' => 3, 'regime_apuracao_sn' => 1, 'aliquota_simples' => 6, 'tributos_aprox' => null]);
        $this->conferir($this->aj(['aliquota_simples' => '6', 'deducao_tipo' => 'valor', 'deducao_valor' => '10']))->assertSessionHasErrors('ajustes.deducao_tipo');   // E0441

        $this->emitente = $this->empresa(['cnpj' => '11222333000181', 'op_simples_nacional' => 3, 'regime_apuracao_sn' => 2, 'aliquota_simples' => 6, 'tributos_aprox' => null]);
        $this->conferir($this->aj(['aliquota_simples' => '6', 'deducao_tipo' => 'valor', 'deducao_valor' => '10']))->assertOk();
    }

    public function test_pis_cofins_e_retencoes_federais(): void
    {
        // CST diferente de 00 pede base e alíquotas
        $this->conferir($this->aj(['pis_cst' => '01']))->assertSessionHasErrors(['ajustes.pis_aliq_pis', 'ajustes.pis_aliq_cofins']);   // a base em branco vale o valor do serviço
        $this->conferir($this->aj(['pis_cst' => '01', 'pis_bc' => '900', 'pis_aliq_pis' => '0,65', 'pis_aliq_cofins' => '3']))->assertSessionHasErrors('ajustes.pis_bc');   // E0677
        $this->conferir($this->aj(['pis_cst' => '77']))->assertSessionHasErrors('ajustes.pis_cst');

        // retenção 0 proíbe a CSLL (E0720); retenção com CSLL a exige (E0724)
        $this->conferir($this->aj(['pis_ret' => '0', 'ret_csll' => '5']))->assertSessionHasErrors('ajustes.ret_csll');
        $this->conferir($this->aj(['pis_ret' => '3']))->assertSessionHasErrors('ajustes.ret_csll');
        $this->conferir($this->aj(['pis_ret' => '2']))->assertOk();                                          // 2 não retém: CSLL opcional
        $this->conferir($this->aj(['ret_irrf' => '452,50']))->assertSessionHasErrors('ajustes.ret_irrf');    // menor que o serviço (E0700)
        $this->conferir($this->aj(['ret_cp' => '0']))->assertSessionHasErrors('ajustes.ret_cp');

        $ok = $this->aj(['pis_cst' => '01', 'pis_bc' => '452,50', 'pis_aliq_pis' => '0,65', 'pis_aliq_cofins' => '3', 'pis_ret' => '3', 'ret_irrf' => '6,79', 'ret_csll' => '4,52', 'ret_cp' => '10']);
        $this->conferir($ok)->assertOk()->assertSee('01 - Operação tributável com alíquota básica', false)->assertSee('3 - PIS/COFINS/CSLL retidos', false)->assertSee('R$ 6,79');
        $this->emitirCom($ok);
        $this->assertEquals([
            'pis_cofins' => ['cst' => '01', 'tp_ret' => 3, 'bc' => 452.5, 'aliq_pis' => 0.65, 'aliq_cofins' => 3.0],
            'ret_irrf' => 6.79, 'ret_csll' => 4.52, 'ret_cp' => 10.0,
        ], NotaFiscal::firstOrFail()->parametros_dps);
    }

    public function test_federal_nao_se_aplica_a_mei_nem_ao_simples(): void
    {
        $this->emitente = $this->empresa(['cnpj' => '07445371000126', 'op_simples_nacional' => 3, 'regime_apuracao_sn' => 1, 'aliquota_simples' => 6, 'tributos_aprox' => null]);
        // campos federais enviados por engano são ignorados: nada é gravado e a DPS segue sem tribFed
        $this->emitirCom($this->aj(['aliquota_simples' => '6', 'pis_cst' => '01', 'ret_irrf' => '5']))->assertRedirect(route('notas.index'));

        $this->assertNull(NotaFiscal::firstOrFail()->parametros_dps);
    }

    public function test_dps_usa_os_ajustes_da_nota_e_passa_no_xsd(): void
    {
        $padrao = (new DpsBuilder)->montar($this->nota([]));
        $this->assertStringContainsString('<cTribMun>001</cTribMun>', $padrao);
        $this->assertStringContainsString('<cLocPrestacao>3550308</cLocPrestacao>', $padrao);
        $this->assertStringContainsString('<tribISSQN>1</tribISSQN><tpRetISSQN>1</tpRetISSQN>', $padrao);
        $this->assertStringContainsString('<pTotTribFed>13.45</pTotTribFed>', $padrao);
        $this->assertStringNotContainsString('vAjusteBC', $padrao);

        $xml = (new DpsBuilder)->montar($this->nota([
            'codigo_tributacao_municipal' => '002', 'cod_municipio_prestacao' => '3304557', 'retencao_issqn' => 2, 'regime_especial' => 0,
            'tributos_aprox' => ['federal' => 10.5, 'estadual' => 1.0, 'municipal' => 3.0],
        ]));

        $this->assertStringContainsString('<cTribMun>002</cTribMun>', $xml);
        $this->assertStringContainsString('<cLocPrestacao>3304557</cLocPrestacao>', $xml);
        $this->assertStringContainsString('<cLocEmi>3550308</cLocEmi>', $xml);                      // quem emite continua sendo a empresa
        $this->assertStringContainsString('<tpRetISSQN>2</tpRetISSQN>', $xml);
        $this->assertStringNotContainsString('<pAliq>', $xml);   // com município conveniado o Anexo I proíbe pAliq (E0617)
        $this->assertStringContainsString('<pTotTribFed>10.50</pTotTribFed><pTotTribEst>1.00</pTotTribEst><pTotTribMun>3.00</pTotTribMun>', $xml);
        $this->assertSame([], (new ValidadorXsd)->erros($xml));
    }

    public function test_dps_com_imunidade_nao_incidencia_e_deducao_passa_no_xsd(): void
    {
        $imune = (new DpsBuilder)->montar($this->nota(['trib_issqn' => 2, 'tp_imunidade' => 2]));
        $this->assertStringContainsString('<tribISSQN>2</tribISSQN><tpImunidade>2</tpImunidade><tpRetISSQN>1</tpRetISSQN>', $imune);
        $this->assertSame([], (new ValidadorXsd)->erros($imune));

        $nao = (new DpsBuilder)->montar($this->nota(['trib_issqn' => 4]));
        $this->assertStringContainsString('<tribISSQN>4</tribISSQN><tpRetISSQN>1</tpRetISSQN>', $nao);
        $this->assertStringNotContainsString('tpImunidade', $nao);
        $this->assertSame([], (new ValidadorXsd)->erros($nao));

        foreach ([['percentual', 'pAjusteBCISSQN', '10.50'], ['valor', 'vAjusteBCISSQN', '10.50']] as [$tipo, $tag, $valor]) {
            $xml = (new DpsBuilder)->montar($this->nota(['deducao' => ['tipo' => $tipo, 'valor' => 10.5]]));
            $this->assertStringContainsString("</vServPrest><vAjusteBC><{$tag}>{$valor}</{$tag}></vAjusteBC><trib>", $xml);
            $this->assertSame([], (new ValidadorXsd)->erros($xml), $tipo);
        }
    }

    public function test_dps_com_pis_cofins_e_retencoes_federais_passa_no_xsd(): void
    {
        $padrao = (new DpsBuilder)->montar($this->nota([]));
        $this->assertStringContainsString('<tribFed><piscofins><CST>00</CST><tpRetPisCofins>0</tpRetPisCofins></piscofins></tribFed>', $padrao);

        $xml = (new DpsBuilder)->montar($this->nota([
            'pis_cofins' => ['cst' => '01', 'tp_ret' => 3, 'bc' => 452.5, 'aliq_pis' => 0.65, 'aliq_cofins' => 3.0],
            'ret_irrf' => 6.79, 'ret_csll' => 4.52, 'ret_cp' => 10.0,
        ]));

        $this->assertStringContainsString('<piscofins><CST>01</CST><vBCPisCofins>452.50</vBCPisCofins><pAliqPis>0.65</pAliqPis><pAliqCofins>3.00</pAliqCofins><vPis>2.94</vPis><vCofins>13.58</vCofins><tpRetPisCofins>3</tpRetPisCofins></piscofins>', $xml);
        $this->assertStringContainsString('<vRetCP>10.00</vRetCP><vRetIRRF>6.79</vRetIRRF><vRetCSLL>4.52</vRetCSLL></tribFed>', $xml);
        $this->assertSame([], (new ValidadorXsd)->erros($xml));
    }

    public function test_dps_sem_codigo_complementar_quando_o_ajuste_o_remove(): void
    {
        $xml = (new DpsBuilder)->montar($this->nota(['codigo_tributacao_municipal' => '']));

        $this->assertStringNotContainsString('cTribMun', $xml);
        $this->assertSame([], (new ValidadorXsd)->erros($xml));
    }

    public function test_tres_formas_de_informar_o_valor_aproximado_dos_tributos(): void
    {
        // não optante: percentual (padrão) ou valores em R$
        $this->conferir($this->aj(['modo_tributos_aprox' => 'simples']))->assertSessionHasErrors('ajustes.modo_tributos_aprox');   // E0713

        $this->conferir($this->aj(['modo_tributos_aprox' => 'monetario', 'val_federal' => '900']))->assertSessionHasErrors('ajustes.val_federal');   // E0702: no máximo o valor do serviço
        $this->emitirCom($this->aj(['modo_tributos_aprox' => 'monetario', 'val_federal' => '60,83', 'val_estadual' => '', 'val_municipal' => '12,22']))->assertRedirect(route('notas.index'));
        $this->assertEquals(['modo_tributos_aprox' => 'monetario', 'valores_tributos' => ['federal' => 60.83, 'estadual' => 0.0, 'municipal' => 12.22]], NotaFiscal::firstOrFail()->parametros_dps);

        // monetário sem digitar nada: sem ajuste de valores, a DPS calcula pelo percentual da empresa
        NotaFiscal::query()->delete();
        $this->emitirCom($this->aj(['modo_tributos_aprox' => 'monetario']));
        $this->assertSame(['modo_tributos_aprox' => 'monetario'], NotaFiscal::firstOrFail()->parametros_dps);

        // ME/EPP: alíquota do Simples, percentual ou valores
        $this->emitente = $this->empresa(['cnpj' => '07445371000126', 'op_simples_nacional' => 3, 'regime_apuracao_sn' => 1, 'aliquota_simples' => 6, 'tributos_aprox' => null]);
        $this->conferir($this->aj(['modo_tributos_aprox' => 'percentual', 'trib_federal' => '10', 'trib_estadual' => '0', 'trib_municipal' => '2']))->assertOk()->assertSee('Configurar os valores percentuais correspondentes');
        $this->conferir($this->aj(['modo_tributos_aprox' => 'simples', 'aliquota_simples' => '']))->assertSessionHasErrors('ajustes.aliquota_simples');
        $this->conferir($this->aj(['modo_tributos_aprox' => 'nenhum']))->assertSessionHasErrors('ajustes.modo_tributos_aprox');   // indTotTrib é proibido para ME/EPP (E0712)
    }

    public function test_regime_especial_e_so_leitura_na_nota_completa(): void
    {
        $html = $this->get(route('notas.manual'))->assertOk()->getContent();

        $this->assertStringNotContainsString('name="ajustes[regime_especial]"', $html);   // cinza no portal: vem do cadastro da empresa
        $this->assertStringContainsString('Regime especial de tributa', $html);           // aparece junto do prestador (acentos escapados no JS)

        $this->emitirCom($this->aj())->assertRedirect(route('notas.index'));              // e a nota sai sem ele no formulário
        $this->assertNull(NotaFiscal::firstOrFail()->parametros_dps);
    }

    public function test_padrao_federal_da_empresa_vai_na_dps_e_pode_mudar_na_nota(): void
    {
        $this->emitente = $this->empresa(['cnpj' => '07445371000126', 'pis_cofins_cst' => '01', 'aliquota_pis' => 0.65, 'aliquota_cofins' => 3, 'tp_ret_pis_cofins' => 2]);

        $xml = (new DpsBuilder)->montar($this->nota([]));   // $this->nota usa $this->emitente
        $this->assertStringContainsString('<piscofins><CST>01</CST><vBCPisCofins>10.00</vBCPisCofins><pAliqPis>0.65</pAliqPis><pAliqCofins>3.00</pAliqCofins><vPis>0.07</vPis><vCofins>0.30</vCofins><tpRetPisCofins>2</tpRetPisCofins></piscofins>', $xml);
        $this->assertSame([], (new ValidadorXsd)->erros($xml));

        // na tela o painel já vem com o padrão da empresa e só grava o que mudar
        $this->emitirCom($this->aj(['pis_cst' => '01', 'pis_ret' => '2', 'pis_aliq_pis' => '0,65', 'pis_aliq_cofins' => '3']))->assertRedirect(route('notas.index'));
        $this->assertNull(NotaFiscal::firstOrFail()->parametros_dps);

        NotaFiscal::query()->delete();
        $this->emitirCom($this->aj(['pis_cst' => '01', 'pis_ret' => '2', 'pis_aliq_pis' => '1,65', 'pis_aliq_cofins' => '3']));
        $this->assertEquals(['pis_cofins' => ['cst' => '01', 'tp_ret' => 2, 'bc' => 452.5, 'aliq_pis' => 1.65, 'aliq_cofins' => 3.0]], NotaFiscal::firstOrFail()->parametros_dps);
    }

    public function test_dps_com_valores_monetarios_dos_tributos_passa_no_xsd(): void
    {
        $calculado = (new DpsBuilder)->montar($this->nota(['modo_tributos_aprox' => 'monetario']));   // valor 10,00 × 13,45% / 0% / 2,70%
        $this->assertStringContainsString('<totTrib><vTotTrib><vTotTribFed>1.35</vTotTribFed><vTotTribEst>0.00</vTotTribEst><vTotTribMun>0.27</vTotTribMun></vTotTrib></totTrib>', $calculado);
        $this->assertSame([], (new ValidadorXsd)->erros($calculado));

        $digitado = (new DpsBuilder)->montar($this->nota(['modo_tributos_aprox' => 'monetario', 'valores_tributos' => ['federal' => 2.0, 'estadual' => 0.0, 'municipal' => 0.5]]));
        $this->assertStringContainsString('<vTotTribFed>2.00</vTotTribFed><vTotTribEst>0.00</vTotTribEst><vTotTribMun>0.50</vTotTribMun>', $digitado);
        $this->assertSame([], (new ValidadorXsd)->erros($digitado));

        $this->emitente = $this->empresa(['cnpj' => '07445371000126', 'op_simples_nacional' => 3, 'regime_apuracao_sn' => 1, 'aliquota_simples' => 6, 'tributos_aprox' => null]);
        $simples = (new DpsBuilder)->montar($this->nota([]));
        $this->assertStringContainsString('<totTrib><pTotTribSN>6.00</pTotTribSN></totTrib>', $simples);
        $this->assertSame([], (new ValidadorXsd)->erros($simples));
    }

    public function test_empresa_salva_a_forma_do_valor_aproximado_e_a_tributacao_federal(): void
    {
        $dados = fn (array $extra) => $extra + [
            'cnpj' => '19534920000118', 'razao_social' => 'BR Controle Ltda', 'codigo_municipio' => '3550308', 'codigo_tributacao_municipal' => '001',
            'op_simples_nacional' => '1', 'regime_especial' => '0', 'serie_dps' => '00001', 'ambiente' => '2',
            'modo_tributos_aprox' => 'percentual', 'tributos_aprox' => ['federal' => '13.45', 'estadual' => '0', 'municipal' => '2.7'],
            'pis_cofins_cst' => '00', 'tp_ret_pis_cofins' => '0',
        ];
        $dados2 = fn (array $e) => array_replace_recursive($dados([]), $e);
        $url = route('emitentes.update', $this->emitente);

        // simples não vale para não optante (E0713); retenção com CSLL não entra no padrão; CST diferente de 00 pede alíquotas
        $this->put($url, $dados2(['modo_tributos_aprox' => 'simples']))->assertSessionHasErrors('modo_tributos_aprox');
        $this->put($url, $dados2(['tp_ret_pis_cofins' => '3']))->assertSessionHasErrors('tp_ret_pis_cofins');
        $this->put($url, $dados2(['pis_cofins_cst' => '01']))->assertSessionHasErrors(['aliquota_pis', 'aliquota_cofins']);
        $this->put($url, $dados2(['tributos_aprox' => ['federal' => '']]))->assertSessionHasErrors('tributos_aprox.federal');

        $this->put($url, $dados2(['modo_tributos_aprox' => 'monetario', 'pis_cofins_cst' => '01', 'aliquota_pis' => '0.65', 'aliquota_cofins' => '3', 'tp_ret_pis_cofins' => '2']))->assertSessionHasNoErrors();
        $e = $this->emitente->fresh();
        $this->assertSame('monetario', $e->modo_tributos_aprox);
        $this->assertSame('01', $e->pis_cofins_cst);
        $this->assertSame(2, (int) $e->tp_ret_pis_cofins);
        $this->assertEquals(0.65, (float) $e->aliquota_pis);
        $this->assertEqualsCanonicalizing(['federal' => 13.45, 'estadual' => 0, 'municipal' => 2.7], array_map('floatval', $e->tributos_aprox));

        // volta para 00: as alíquotas do PIS/COFINS são limpas
        $this->put($url, $dados([]))->assertSessionHasNoErrors();
        $e = $this->emitente->fresh();
        $this->assertSame('percentual', $e->modo_tributos_aprox);
        $this->assertNull($e->aliquota_pis);

        // MEI: sem tributação federal e sem percentuais quando não informa
        $this->put($url, $dados(['op_simples_nacional' => '2', 'codigo_tributacao_municipal' => '', 'modo_tributos_aprox' => 'nenhum', 'tributos_aprox' => null, 'pis_cofins_cst' => '01']))->assertSessionHasNoErrors();
        $e = $this->emitente->fresh();
        $this->assertSame('nenhum', $e->modo_tributos_aprox);
        $this->assertSame('00', $e->pis_cofins_cst);
        $this->assertNull($e->tributos_aprox);
    }

    public function test_nbs_em_lista_suspensa_com_busca_por_codigo_e_nome(): void
    {
        $this->assertTrue(\App\Support\ListaNbs::existe('115080000'));
        $this->assertSame('Serviços de manutenção de aplicativos e programas', \App\Support\ListaNbs::descricao('115080000'));
        $this->assertGreaterThan(900, count(\App\Support\ListaNbs::todos()));

        // pelo código (prefixo, com ou sem pontos) e pelo nome (sem acento)
        $this->getJson(route('nbs.buscar', ['q' => '1150']))->assertOk()->assertJsonFragment(['codigo' => '115080000', 'descricao' => 'Serviços de manutenção de aplicativos e programas']);
        $this->getJson(route('nbs.buscar', ['q' => '1.1508']))->assertOk()->assertJsonFragment(['codigo' => '115080000']);
        $this->getJson(route('nbs.buscar', ['q' => 'manutencao aplicativos']))->assertOk()->assertJsonFragment(['codigo' => '115080000']);
        $this->getJson(route('nbs.buscar', ['q' => 'zzzzzz']))->assertOk()->assertExactJson([]);
        $this->assertCount(50, $this->getJson(route('nbs.buscar'))->assertOk()->json());   // sem texto: as primeiras opções

        // o campo da empresa e o da emissão manual usam a lista
        $this->get(route('emitentes.editar', $this->emitente))->assertSee(str_replace('/', '\/', route('nbs.buscar')), false)->assertSee('name="codigo_nbs_padrao"', false);
        $this->get(route('notas.manual'))->assertSee(str_replace('/', '\/', route('nbs.buscar')), false)->assertSee('name="codigo_nbs"', false)->assertDontSee('x-model="nbs"', false);
    }

    private function dadosEmpresa(array $extra = []): array
    {
        return array_replace_recursive([
            'cnpj' => '19534920000118', 'razao_social' => 'BR Controle Ltda', 'codigo_municipio' => '3550308', 'codigo_tributacao_municipal' => '001',
            'op_simples_nacional' => '1', 'regime_especial' => '0', 'serie_dps' => '00001', 'informar_serie_dps' => '1', 'ambiente' => '2',
            'modo_tributos_aprox' => 'percentual', 'tributos_aprox' => ['federal' => '13.45', 'estadual' => '0', 'municipio' => '2.7', 'municipal' => '2.7'],
            'pis_cofins_cst' => '00', 'tp_ret_pis_cofins' => '0',
            'trib_issqn' => '1', 'retencao_issqn' => '1', 'deducao_tipo' => 'none',
        ], $extra);
    }

    public function test_empresa_configura_a_tributacao_municipal_padrao_com_as_travas_da_sefin(): void
    {
        $url = route('emitentes.update', $this->emitente);

        // imunidade pede o tipo; com imunidade/não incidência não há regime especial, retenção nem dedução
        $this->put($url, $this->dadosEmpresa(['trib_issqn' => '2']))->assertSessionHasErrors('tp_imunidade');
        $this->put($url, $this->dadosEmpresa(['trib_issqn' => '4', 'regime_especial' => '3']))->assertSessionHasErrors('trib_issqn');
        $this->put($url, $this->dadosEmpresa(['trib_issqn' => '4', 'retencao_issqn' => '2']))->assertSessionHasErrors('retencao_issqn');
        $this->put($url, $this->dadosEmpresa(['trib_issqn' => '4', 'deducao_tipo' => 'percentual', 'deducao_valor' => '10']))->assertSessionHasErrors('deducao_tipo');
        $this->put($url, $this->dadosEmpresa(['trib_issqn' => '3']))->assertSessionHasErrors('trib_issqn');                       // exportação indisponível

        // retenção e dedução: regras de regime
        $this->put($url, $this->dadosEmpresa(['retencao_issqn' => '2', 'regime_especial' => '3']))->assertSessionHasErrors('retencao_issqn');   // E0588
        $this->put($url, $this->dadosEmpresa(['deducao_tipo' => 'percentual', 'deducao_valor' => '150']))->assertSessionHasErrors('deducao_valor');
        $this->put($url, $this->dadosEmpresa(['deducao_tipo' => 'valor', 'deducao_valor' => '']))->assertSessionHasErrors('deducao_valor');
        $this->put($url, $this->dadosEmpresa(['local_prestacao' => 'outro', 'municipio_prestacao' => 'Cidadelandia', 'uf_prestacao' => 'RJ']))->assertSessionHasErrors('municipio_prestacao');
        $this->put($url, $this->dadosEmpresa(['op_simples_nacional' => '3', 'regime_apuracao_sn' => '1', 'aliquota_simples' => '6', 'modo_tributos_aprox' => 'simples', 'tributos_aprox' => null,
            'deducao_tipo' => 'valor', 'deducao_valor' => '10']))->assertSessionHasErrors('deducao_tipo');                         // E0441
        $this->put($url, $this->dadosEmpresa(['op_simples_nacional' => '2', 'codigo_tributacao_municipal' => '', 'modo_tributos_aprox' => 'nenhum', 'tributos_aprox' => null,
            'retencao_issqn' => '2']))->assertSessionHasErrors('retencao_issqn');                                                   // MEI (E0583)

        // tudo certo
        $this->put($url, $this->dadosEmpresa(['trib_issqn' => '2', 'tp_imunidade' => '2']))->assertSessionHasNoErrors();
        $e = $this->emitente->fresh();
        $this->assertSame(2, (int) $e->trib_issqn);
        $this->assertSame(2, (int) $e->tp_imunidade);

        $this->put($url, $this->dadosEmpresa(['retencao_issqn' => '2', 'deducao_tipo' => 'percentual', 'deducao_valor' => '10.5', 'local_prestacao' => 'outro', 'municipio_prestacao' => 'Rio de Janeiro', 'uf_prestacao' => 'rj']))->assertSessionHasNoErrors();
        $e = $this->emitente->fresh();
        $this->assertSame(1, (int) $e->trib_issqn);
        $this->assertNull($e->tp_imunidade);                      // volta a tributável: o tipo de imunidade é limpo
        $this->assertSame(2, (int) $e->retencao_issqn);
        $this->assertSame('percentual', $e->deducao_tipo);
        $this->assertEquals(10.5, (float) $e->deducao_valor);
        $this->assertSame('3304557', $e->codigo_municipio_prestacao);

        // município da prestação igual ao da empresa não precisa ficar gravado
        $this->put($url, $this->dadosEmpresa(['local_prestacao' => 'outro', 'municipio_prestacao' => 'São Paulo', 'uf_prestacao' => 'SP']))->assertSessionHasNoErrors();
        $this->assertNull($this->emitente->fresh()->codigo_municipio_prestacao);
    }

    public function test_dps_parte_dos_padroes_da_empresa_e_a_nota_pode_mudar(): void
    {
        $imune = $this->empresa(['cnpj' => '07445371000126', 'trib_issqn' => 2, 'tp_imunidade' => 3]);
        $this->emitente = $imune;
        $xml = (new DpsBuilder)->montar($this->nota([]));
        $this->assertStringContainsString('<tribISSQN>2</tribISSQN><tpImunidade>3</tpImunidade><tpRetISSQN>1</tpRetISSQN>', $xml);
        $this->assertSame([], (new ValidadorXsd)->erros($xml));

        // a nota volta a ser tributável: o ajuste da nota vence o padrão da empresa
        $xml = (new DpsBuilder)->montar($this->nota(['trib_issqn' => 1]));
        $this->assertStringContainsString('<tribISSQN>1</tribISSQN><tpRetISSQN>1</tpRetISSQN>', $xml);

        $this->emitente = $this->empresa(['cnpj' => '11222333000181', 'retencao_issqn' => 2, 'deducao_tipo' => 'percentual', 'deducao_valor' => 10, 'codigo_municipio_prestacao' => '3304557', 'local_prestacao' => 'outro']);
        $xml = (new DpsBuilder)->montar($this->nota([]));
        $this->assertStringContainsString('<tpRetISSQN>2</tpRetISSQN>', $xml);
        $this->assertStringContainsString('</vServPrest><vAjusteBC><pAjusteBCISSQN>10.00</pAjusteBCISSQN></vAjusteBC><trib>', $xml);
        $this->assertStringContainsString('<cLocPrestacao>3304557</cLocPrestacao>', $xml);
        $this->assertStringContainsString('<cLocEmi>3550308</cLocEmi>', $xml);
        $this->assertSame([], (new ValidadorXsd)->erros($xml));

        // a nota cancela a dedução e a retenção da empresa
        $xml = (new DpsBuilder)->montar($this->nota(['deducao' => ['tipo' => 'none'], 'retencao_issqn' => 1, 'cod_municipio_prestacao' => '3550308']));
        $this->assertStringNotContainsString('vAjusteBC', $xml);
        $this->assertStringContainsString('<tpRetISSQN>1</tpRetISSQN>', $xml);
        $this->assertStringContainsString('<cLocPrestacao>3550308</cLocPrestacao>', $xml);
    }

    public function test_nota_manual_parte_do_padrao_da_empresa_e_so_grava_a_diferenca(): void
    {
        $this->emitente = $this->empresa(['cnpj' => '07445371000126', 'regime_apuracao_sn' => 1, 'deducao_tipo' => 'percentual', 'deducao_valor' => 10, 'trib_issqn' => 1]);
        $this->emitente->forceFill(['deducao_tipo' => 'percentual', 'deducao_valor' => 10])->save();

        // o painel já vem com o padrão da empresa
        $this->get(route('notas.manual'))->assertOk()->assertSee('deducao_tipo', false)->assertSee('percentual', false);

        // sem mexer, a nota sai com o padrão da empresa e não grava ajuste
        $this->emitirCom($this->aj(['deducao_tipo' => 'percentual', 'deducao_valor' => '10']))->assertRedirect(route('notas.index'));
        $this->assertNull(NotaFiscal::firstOrFail()->parametros_dps);

        // a nota decide não aplicar a dedução da empresa
        NotaFiscal::query()->delete();
        $this->conferir($this->aj(['deducao_tipo' => 'none']))->assertOk()->assertSee('Dedução/redução da base de cálculo');
        $this->emitirCom($this->aj(['deducao_tipo' => 'none']));
        $this->assertSame(['deducao' => ['tipo' => 'none']], NotaFiscal::firstOrFail()->parametros_dps);

        // empresa imune: a nota pode voltar a tributável, gravando só isso
        NotaFiscal::query()->delete();
        $this->emitente = $this->empresa(['cnpj' => '11222333000181', 'trib_issqn' => 2, 'tp_imunidade' => 2]);
        $this->emitirCom($this->aj(['trib_issqn' => '2', 'tp_imunidade' => '2']))->assertRedirect(route('notas.index'));
        $this->assertNull(NotaFiscal::firstOrFail()->parametros_dps);
        NotaFiscal::query()->delete();
        $this->emitirCom($this->aj(['trib_issqn' => '1']));
        $this->assertSame(['trib_issqn' => 1], NotaFiscal::firstOrFail()->parametros_dps);
    }

    public function test_local_da_prestacao_do_destinatario_usa_o_municipio_do_cliente_da_nota(): void
    {
        $this->emitente = $this->empresa(['cnpj' => '11222333000181', 'local_prestacao' => 'tomador']);
        $nota = $this->nota([]);
        $nota->setRelation('tomador', new \App\Models\Tomador(['codigo_municipio' => '3304557']));
        $this->assertStringContainsString('<cLocPrestacao>3304557</cLocPrestacao>', (new DpsBuilder)->montar($nota));
        $this->assertStringContainsString('<cLocEmi>3550308</cLocEmi>', (new DpsBuilder)->montar($nota));
    }

    public function test_campos_obrigatorios_ficam_marcados_e_o_erro_aparece_em_vermelho_no_campo(): void
    {
        $html = $this->get(route('emitentes.editar', $this->emitente))->assertOk()->getContent();
        foreach (['razao_social', 'cnpj', 'serie_dps', 'ambiente', 'pis_cofins_cst', 'tributos_aprox[federal]'] as $campo) {
            $this->assertMatchesRegularExpression('/<label for="'.preg_quote($campo, '/').'" class="campo-rotulo obrigatorio">/', $html, "sem asterisco em {$campo}");
        }
        $this->assertDoesNotMatchRegularExpression('/<label for="inscricao_municipal" class="campo-rotulo obrigatorio">/', $html);   // opcional não leva asterisco

        // percentuais em branco: erro no próprio campo, com o nome legível (não "tributos aprox.federal")
        $dados = $this->dadosEmpresa(['tributos_aprox' => ['federal' => '', 'estadual' => '', 'municipal' => '']]);
        $r = $this->followingRedirects()->from(route('emitentes.editar', $this->emitente))->put(route('emitentes.update', $this->emitente), $dados)->assertOk();
        $r->assertSee('tributos aproximados (federal)')->assertSee('erro-campo', false)->assertSee('campo-invalido', false);
        $r->assertDontSee('tributos aprox.federal');

        // cadastro de cliente e emissão manual também marcam o que é obrigatório
        $this->get(route('tomadores.criar'))->assertSee('class="campo-rotulo obrigatorio">CPF ou CNPJ', false)->assertSee('class="campo-rotulo obrigatorio">Razão social / nome', false);
        $this->get(route('notas.manual'))->assertSee('class="campo-rotulo obrigatorio">Valor (R$)', false)->assertSee('class="campo-rotulo obrigatorio">Descrição do serviço', false);
    }

    public function test_empresa_cadastra_os_codigos_complementares_municipais_da_sua_prefeitura(): void
    {
        $url = route('emitentes.update', $this->emitente);
        $lista = [
            ['codigo' => '001', 'descricao' => 'Suporte tecnico em informatica', 'servico' => '010701'],
            ['codigo' => '002', 'descricao' => '', 'servico' => ''],
            ['codigo' => '', 'descricao' => '', 'servico' => ''],   // linha em branco: ignorada
        ];

        $this->put($url, $this->dadosEmpresa(['codigos' => $lista]))->assertSessionHasNoErrors();

        $salvos = $this->emitente->codigosMunicipais()->get();
        $this->assertSame(['001', '002'], $salvos->pluck('codigo')->all());
        $this->assertSame('010701', $salvos[0]->codigo_servico);
        $this->assertNull($salvos[1]->descricao);

        // a tela da empresa e a nota completa oferecem a lista
        $html = $this->get(route('emitentes.editar', $this->emitente))->assertOk()->getContent();
        $this->assertStringContainsString('lista-codigos-municipais', $html);
        $this->assertStringContainsString('Suporte tecnico em informatica', $html);
        $this->get(route('notas.manual'))->assertOk()->assertSee('aj-ctm-lista', false)->assertSee('Suporte tecnico em informatica', false);

        // salvar de novo troca a lista (remover um código funciona)
        $this->put($url, $this->dadosEmpresa(['codigos' => [['codigo' => '001', 'descricao' => 'Novo texto', 'servico' => '']]]))->assertSessionHasNoErrors();
        $this->assertSame(['001'], $this->emitente->codigosMunicipais()->pluck('codigo')->all());
        $this->assertSame('Novo texto', $this->emitente->codigosMunicipais()->first()->descricao);
    }

    public function test_lista_de_codigos_municipais_valida_repeticao_formato_e_servico(): void
    {
        $url = route('emitentes.update', $this->emitente);

        $this->put($url, $this->dadosEmpresa(['codigos' => [['codigo' => '001'], ['codigo' => '001']]]))->assertSessionHasErrors('codigos');
        $this->put($url, $this->dadosEmpresa(['codigos' => [['codigo' => '12']]]))->assertSessionHasErrors('codigos.0.codigo');
        $this->put($url, $this->dadosEmpresa(['codigos' => [['codigo' => '001', 'servico' => '999999']]]))->assertSessionHasErrors('codigos.0.servico');
        $this->assertSame(0, $this->emitente->codigosMunicipais()->count());
    }

    public function test_codigo_padrao_da_empresa_entra_na_lista_e_a_lista_e_por_empresa(): void
    {
        $this->put(route('emitentes.update', $this->emitente), $this->dadosEmpresa(['codigo_tributacao_municipal' => '007']))->assertSessionHasNoErrors();
        $this->assertSame(['007'], $this->emitente->codigosMunicipais()->pluck('codigo')->all());   // o padrão sempre consta na lista

        // outra empresa não enxerga os códigos desta
        $outra = $this->empresa(['cnpj' => '07445371000126']);
        $this->assertSame([], $outra->codigosMunicipais()->pluck('codigo')->all());
        $this->assertSame([], $outra->listaCodigosMunicipais());
    }
}

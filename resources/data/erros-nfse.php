<?php

/**
 * Textos próprios (revisados) para os erros da prefeitura que mais acontecem.
 * Quem não está aqui usa a orientação base gerada do Anexo I (erros-nfse-base.php), então nenhum código fica sem resposta.
 *
 * Campos (todos opcionais, o que faltar vem da base):
 *   acao          nota | emitente | cliente | contador | sistema   (muda o destino do botão)
 *   titulo        frase curta do problema
 *   aconteceu     o que houve, em português simples
 *   como_resolver o passo a passo
 *   campo         id do campo na tela de destino (a tela rola até ele e o destaca)
 *   contador      texto pronto para a pessoa encaminhar ao contador/prefeitura
 *
 * Para incluir um código novo: acrescente uma linha aqui. Para ver o que está sem texto, use o painel "Erros sem tradução" (master).
 */

$contabilidade = static fn (string $pedido): string => $pedido."\n\nSe preferir, confira direto com a prefeitura (cadastro do contribuinte / CNC NFS-e).";

return [
    // ---------- Inscrição municipal (cadastro da empresa na prefeitura) ----------
    'E0116' => [
        'acao' => 'emitente', 'campo' => 'inscricao_municipal',
        'titulo' => 'Falta a inscrição municipal da empresa',
        'aconteceu' => 'A prefeitura tem a sua empresa cadastrada com inscrição municipal, e ela precisa ir na nota. No cadastro da empresa o campo está vazio.',
        'como_resolver' => 'Informe a inscrição municipal no cadastro da empresa e reenvie a nota.',
        'contador' => $contabilidade('Preciso da inscrição municipal (CCM) da empresa para emitir NFS-e. A prefeitura exige que ela vá na nota (erro E0116).'),
    ],
    'E0119' => [
        'acao' => 'contador', 'campo' => 'inscricao_municipal',
        'titulo' => 'Inscrição municipal sem autorização para emitir NFS-e',
        'aconteceu' => 'A inscrição municipal informada existe, mas a prefeitura não a autorizou a emitir NFS-e.',
        'como_resolver' => 'Confira se a inscrição no cadastro da empresa está correta. Se estiver, a contabilidade precisa regularizar a autorização de emissão de NFS-e na prefeitura. Depois reenvie.',
        'contador' => $contabilidade('A inscrição municipal da empresa não está autorizada a emitir NFS-e no sistema nacional (erro E0119). Preciso que a autorização seja regularizada.'),
    ],
    'E0120' => [
        'acao' => 'emitente', 'campo' => 'inscricao_municipal',
        'titulo' => 'A inscrição municipal não deve ir na nota',
        'aconteceu' => 'A prefeitura não tem cadastro complementar desta empresa no sistema nacional, por isso a inscrição municipal não pode ir na nota.',
        'como_resolver' => 'Apague a inscrição municipal do cadastro da empresa e reenvie. Se a empresa tem inscrição, peça à contabilidade para cadastrá-la no sistema nacional da prefeitura.',
        'contador' => $contabilidade('A prefeitura recusou a inscrição municipal da empresa porque não há cadastro complementar dela no sistema nacional (erro E0120). Preciso saber se a empresa deve ser cadastrada lá.'),
    ],
    'E0124' => [
        'acao' => 'contador', 'campo' => 'inscricao_municipal',
        'titulo' => 'Inscrição municipal inativa',
        'aconteceu' => 'A inscrição municipal da empresa está inativa na prefeitura na data de competência da nota.',
        'como_resolver' => 'Confira a data de competência. Se a data estiver certa, a contabilidade precisa reativar ou regularizar a inscrição na prefeitura.',
        'contador' => $contabilidade('A inscrição municipal da empresa consta como inativa na data de competência da nota (erro E0124). Preciso que seja regularizada.'),
    ],

    // ---------- Série, número e ambiente ----------
    'E0010' => [
        'acao' => 'emitente', 'campo' => 'serie_dps',
        'titulo' => 'Série fora da faixa aceita',
        'aconteceu' => 'A série da nota não pertence à faixa permitida para este tipo de emissor.',
        'como_resolver' => 'No cadastro da empresa, use uma série de 00001 a 49999 e reenvie.',
    ],
    'E0014' => [
        'acao' => 'emitente', 'campo' => 'serie_dps',
        'titulo' => 'Número da nota já usado',
        'aconteceu' => 'Já existe uma NFS-e com esta série e este número da DPS.',
        'como_resolver' => 'No cadastro da empresa, ajuste o próximo número da DPS (card "Numeração da DPS") para um número maior e reenvie.',
    ],
    'E0006' => [
        'acao' => 'sistema',
        'titulo' => 'Ambiente da nota diferente do ambiente de envio',
        'aconteceu' => 'A nota foi montada para um ambiente (teste ou produção) e enviada para o outro.',
        'como_resolver' => 'Avise o suporte informando o número da nota. Se você acabou de trocar o ambiente da empresa, reenvie.',
    ],

    // ---------- Datas ----------
    'E0015' => [
        'acao' => 'nota', 'campo' => 'data_competencia',
        'titulo' => 'Data de competência no futuro',
        'aconteceu' => 'A data de competência não pode ser depois do dia da emissão.',
        'como_resolver' => 'Informe uma data de competência de hoje ou anterior.',
    ],
    'E0016' => [
        'acao' => 'nota', 'campo' => 'data_competencia',
        'titulo' => 'Data de competência antes do início do convênio',
        'aconteceu' => 'A prefeitura só aceita notas a partir da data em que passou a usar o sistema nacional.',
        'como_resolver' => 'Use uma data de competência mais recente. Se precisar de uma data anterior, fale com a contabilidade.',
    ],
    'E1270' => [
        'acao' => 'nota', 'campo' => 'data_competencia',
        'titulo' => 'Data de competência antes do início do convênio',
        'aconteceu' => 'A prefeitura só aceita notas a partir da data em que passou a usar o sistema nacional.',
        'como_resolver' => 'Use uma data de competência mais recente. Se precisar de uma data anterior, fale com a contabilidade.',
    ],
    'E0018' => [
        'acao' => 'nota', 'campo' => 'data_competencia',
        'titulo' => 'Data de competência antes da abertura do CNPJ',
        'aconteceu' => 'A data de competência é anterior à data de inscrição do CNPJ da empresa na Receita.',
        'como_resolver' => 'Informe uma data de competência depois da abertura da empresa.',
    ],
    'E0023' => [
        'acao' => 'nota', 'campo' => 'data_competencia',
        'titulo' => 'Data de competência antes do cadastro municipal',
        'aconteceu' => 'A data de competência é anterior à data da inscrição municipal da empresa na prefeitura.',
        'como_resolver' => 'Use uma data depois da inscrição municipal. Em caso de dúvida, confirme a data com a contabilidade.',
    ],
    'E0025' => [
        'acao' => 'nota', 'campo' => 'data_competencia',
        'titulo' => 'Data de competência antes da autorização de uso',
        'aconteceu' => 'A data de competência é anterior à data em que a prefeitura autorizou a empresa a emitir NFS-e.',
        'como_resolver' => 'Use uma data depois da autorização. Em caso de dúvida, confirme a data com a contabilidade.',
    ],

    // ---------- Município e CNPJ da empresa ----------
    'E0037' => ['acao' => 'emitente', 'campo' => 'cidade-emitente', 'titulo' => 'Município da empresa sem convênio no sistema nacional', 'aconteceu' => 'O município informado no cadastro da empresa não existe na lista de municípios conveniados ao sistema nacional.', 'como_resolver' => 'Confira o município no cadastro da empresa.'],
    'E0038' => ['acao' => 'contador', 'campo' => 'cidade-emitente', 'titulo' => 'Convênio do município não está ativo', 'aconteceu' => 'A prefeitura da empresa ainda não está com o convênio ativo no sistema nacional da NFS-e.', 'como_resolver' => 'Confirme com a prefeitura ou a contabilidade se o município já emite pelo sistema nacional.'],
    'E0039' => ['acao' => 'contador', 'campo' => 'cidade-emitente', 'titulo' => 'Município não usa o emissor nacional', 'aconteceu' => 'A prefeitura da empresa usa um sistema próprio, e não o emissor público nacional.', 'como_resolver' => 'Confirme com a prefeitura ou a contabilidade como a empresa deve emitir NFS-e nesse município.'],
    'E0041' => ['acao' => 'emitente', 'campo' => 'cidade-emitente', 'titulo' => 'Município diferente do cadastro do MEI', 'aconteceu' => 'Para MEI, o município do emissor precisa ser o mesmo do CNPJ na Receita.', 'como_resolver' => 'Corrija o município no cadastro da empresa para o mesmo do CNPJ.'],
    'E0080' => ['acao' => 'emitente', 'campo' => 'cnpj', 'titulo' => 'CNPJ da empresa inválido', 'aconteceu' => 'A prefeitura recusou o CNPJ do prestador.', 'como_resolver' => 'Confira o CNPJ no cadastro da empresa.'],
    'E0082' => ['acao' => 'emitente', 'campo' => 'cnpj', 'titulo' => 'CNPJ da empresa não encontrado na Receita', 'aconteceu' => 'O CNPJ do prestador não existe no cadastro da Receita na data de competência.', 'como_resolver' => 'Confira o CNPJ e a data de competência da nota.'],
    'E0084' => ['acao' => 'contador', 'campo' => 'cnpj', 'titulo' => 'Empresa sem estabelecimento no município', 'aconteceu' => 'O CNPJ não tem estabelecimento nem domicílio no município da nota na data de competência.', 'como_resolver' => 'Confirme com a contabilidade o município de registro da empresa e se ele é o mesmo do cadastro aqui.', 'contador' => $contabilidade('A prefeitura informou que o CNPJ da empresa não tem estabelecimento no município da nota (erro E0084). Preciso confirmar o município de registro.')],

    // ---------- Regime tributário ----------
    'E0160' => ['acao' => 'emitente', 'campo' => 'op_simples_nacional', 'titulo' => 'Situação no Simples Nacional diferente da Receita', 'aconteceu' => 'A opção do Simples Nacional do cadastro da empresa não confere com o cadastro oficial na data da nota.', 'como_resolver' => 'Corrija a situação no Simples Nacional no cadastro da empresa (não optante, optante ME/EPP ou MEI) conforme o cadastro oficial. Se tiver dúvida, pergunte à contabilidade.', 'contador' => $contabilidade('Qual é a situação da empresa no Simples Nacional na data da nota (não optante, ME/EPP ou MEI)? A prefeitura recusou a informação atual (erro E0160).')],
    'E0161' => ['acao' => 'contador', 'campo' => 'op_simples_nacional', 'titulo' => 'MEI com data de competência após agosto de 2023', 'aconteceu' => 'Notas de MEI só são aceitas por este caminho com data de competência até 31/08/2023.', 'como_resolver' => 'Confirme com a contabilidade como o MEI deve emitir NFS-e a partir dessa data.'],
    'E0162' => ['acao' => 'emitente', 'campo' => 'regime_apuracao_sn', 'titulo' => 'Regime de apuração não se aplica', 'aconteceu' => 'Empresa não optante do Simples e MEI não devem informar o regime de apuração dos tributos.', 'como_resolver' => 'No cadastro da empresa, confira a situação no Simples Nacional.'],
    'E0166' => ['acao' => 'emitente', 'campo' => 'regime_apuracao_sn', 'titulo' => 'Falta o regime de apuração do Simples', 'aconteceu' => 'Empresa optante do Simples Nacional (ME/EPP) precisa informar como apura os tributos.', 'como_resolver' => 'Escolha o regime de apuração no cadastro da empresa e reenvie.'],
    'E0172' => ['acao' => 'emitente', 'campo' => 'regime_especial', 'titulo' => 'Regime especial incompatível com a tributação', 'aconteceu' => 'Em operação com imunidade, não incidência ou exportação, o regime especial de tributação precisa ser "Nenhum".', 'como_resolver' => 'Ajuste o regime especial ou a tributação do ISSQN no cadastro da empresa.'],
    'E0174' => ['acao' => 'emitente', 'campo' => 'regime_especial', 'titulo' => 'MEI não tem regime especial', 'aconteceu' => 'Para MEI, o regime especial de tributação deve ser "Nenhum".', 'como_resolver' => 'Escolha "Nenhum" no regime especial do cadastro da empresa.'],
    'E0175' => ['acao' => 'emitente', 'campo' => 'regime_especial', 'titulo' => 'Regime especial incompatível com o Simples', 'aconteceu' => 'Quando o ISSQN é apurado pelo Simples Nacional, o regime especial deve ser "Nenhum".', 'como_resolver' => 'Escolha "Nenhum" no regime especial do cadastro da empresa.'],
    'E0177' => ['acao' => 'contador', 'campo' => 'regime_especial', 'titulo' => 'Regime especial não aceito pelo município', 'aconteceu' => 'O regime especial de tributação escolhido não é admitido pela prefeitura.', 'como_resolver' => 'Confirme com a contabilidade o regime especial correto para o município e ajuste o cadastro.'],

    // ---------- Serviço ----------
    'E0302' => ['acao' => 'emitente', 'campo' => 'local_prestacao', 'titulo' => 'Município da prestação inválido', 'aconteceu' => 'O município onde o serviço é prestado não existe na tabela do IBGE.', 'como_resolver' => 'Corrija o local da prestação no cadastro da empresa (ou na nota completa).'],
    'E0314' => ['acao' => 'emitente', 'campo' => 'lista-codigos', 'titulo' => 'Código complementar municipal não aceito', 'aconteceu' => 'O código complementar municipal informado não existe ou não é administrado pela prefeitura na data da nota.', 'como_resolver' => 'Confira, na lista de códigos do cadastro da empresa, o código que a prefeitura usa para este serviço (como aparece no Emissor Nacional). Se tiver dúvida, pergunte à contabilidade.', 'contador' => $contabilidade('Qual é o código complementar municipal (3 dígitos) que a prefeitura usa para o serviço da empresa? O código atual foi recusado (erro E0314).')],
    'E0315' => ['acao' => 'emitente', 'campo' => 'lista-codigos', 'titulo' => 'Código complementar 000 não é permitido', 'aconteceu' => 'O código complementar municipal não pode ser 000.', 'como_resolver' => 'Use outro código na lista de códigos do cadastro da empresa.'],

    // ---------- Dedução, tributação e retenção do ISSQN ----------
    'E0435' => ['acao' => 'emitente', 'campo' => 'deducao_tipo', 'titulo' => 'Dedução não se aplica à tributação', 'aconteceu' => 'Não pode haver dedução ou redução da base em operação com imunidade, exportação ou não incidência.', 'como_resolver' => 'No cadastro da empresa, escolha "Sem dedução/redução" ou mude a tributação.'],
    'E0436' => ['acao' => 'emitente', 'campo' => 'deducao_tipo', 'titulo' => 'MEI não tem dedução', 'aconteceu' => 'Prestador MEI não pode informar dedução ou redução da base de cálculo.', 'como_resolver' => 'Escolha "Sem dedução/redução" no cadastro da empresa.'],
    'E0438' => ['acao' => 'emitente', 'campo' => 'deducao_tipo', 'titulo' => 'Dedução incompatível com regime especial', 'aconteceu' => 'Empresa com regime especial de tributação não pode informar dedução ou redução da base.', 'como_resolver' => 'Escolha "Sem dedução/redução" ou ajuste o regime especial no cadastro da empresa.'],
    'E0439' => ['acao' => 'emitente', 'campo' => 'deducao_tipo', 'titulo' => 'Dedução incompatível com isenção', 'aconteceu' => 'Não pode haver dedução ou redução da base quando o benefício municipal é isenção.', 'como_resolver' => 'Escolha "Sem dedução/redução" no cadastro da empresa.'],
    'E0529' => ['acao' => 'emitente', 'campo' => 'trib_issqn', 'titulo' => 'Tributação do ISSQN não confere com o serviço', 'aconteceu' => 'Para este serviço, o sistema nacional considera a operação tributável e não aceita outra opção de tributação.', 'como_resolver' => 'Escolha "Operação tributável" na tributação do ISSQN do cadastro da empresa.'],
    'E0530' => ['acao' => 'emitente', 'campo' => 'trib_issqn', 'titulo' => 'Operação considerada exportação', 'aconteceu' => 'Para este cenário, o sistema nacional considera exportação de serviço e não aceita outra opção de tributação.', 'como_resolver' => 'Confirme com a contabilidade a tributação correta. Exportação de serviço ainda não é emitida por este sistema.'],
    'E0532' => ['acao' => 'emitente', 'campo' => 'trib_issqn', 'titulo' => 'Serviço sem incidência de ISSQN', 'aconteceu' => 'Para o código de serviço 99.01.01, a tributação do ISSQN deve ser "Não incidência".', 'como_resolver' => 'Escolha "Não incidência" na tributação do ISSQN do cadastro da empresa.'],
    'E0539' => ['acao' => 'emitente', 'campo' => 'trib_issqn', 'titulo' => 'Não incidência não aceita para este serviço', 'aconteceu' => 'O serviço informado é incidente de ISSQN no município, então "Não incidência" não é permitido.', 'como_resolver' => 'Escolha "Operação tributável" na tributação do ISSQN do cadastro da empresa.'],
    'E0580' => ['acao' => 'emitente', 'campo' => 'retencao_issqn', 'titulo' => 'Retenção do ISSQN não permitida nesta tributação', 'aconteceu' => 'Não pode haver retenção do ISSQN em operação com imunidade, exportação ou não incidência.', 'como_resolver' => 'Escolha "Não" em retenção do ISSQN no cadastro da empresa.'],
    'E0583' => ['acao' => 'emitente', 'campo' => 'retencao_issqn', 'titulo' => 'MEI não tem retenção do ISSQN', 'aconteceu' => 'Prestador MEI não pode ter o ISSQN retido pelo tomador.', 'como_resolver' => 'Escolha "Não" em retenção do ISSQN no cadastro da empresa.'],
    'E0588' => ['acao' => 'emitente', 'campo' => 'retencao_issqn', 'titulo' => 'Retenção incompatível com regime especial', 'aconteceu' => 'Empresa com regime especial de tributação não pode ter o ISSQN retido pelo tomador.', 'como_resolver' => 'Escolha "Não" em retenção do ISSQN ou ajuste o regime especial.'],
    'E0594' => ['acao' => 'emitente', 'campo' => 'retencao_issqn', 'titulo' => 'Retenção incompatível com isenção', 'aconteceu' => 'Não pode haver retenção do ISSQN quando o benefício municipal é isenção.', 'como_resolver' => 'Escolha "Não" em retenção do ISSQN no cadastro da empresa.'],
    'E0595' => ['acao' => 'emitente', 'campo' => 'trib_issqn', 'titulo' => 'Alíquota do ISSQN acima de 5%', 'aconteceu' => 'A alíquota informada passa do limite de 5% do ISSQN.', 'como_resolver' => 'Confirme a alíquota com a contabilidade.'],
    'E0600' => ['acao' => 'emitente', 'campo' => 'trib_issqn', 'titulo' => 'MEI não informa alíquota', 'aconteceu' => 'Prestador MEI não pode informar alíquota do ISSQN.', 'como_resolver' => 'Confira a situação no Simples Nacional no cadastro da empresa.'],
    'E0602' => ['acao' => 'emitente', 'campo' => 'trib_issqn', 'titulo' => 'Alíquota incompatível com a tributação', 'aconteceu' => 'Não pode haver alíquota em operação com imunidade, exportação ou não incidência.', 'como_resolver' => 'Confira a tributação do ISSQN no cadastro da empresa.'],
    'E0604' => ['acao' => 'emitente', 'campo' => 'trib_issqn', 'titulo' => 'Alíquota incompatível com regime especial', 'aconteceu' => 'Empresa com regime especial de tributação não pode informar alíquota.', 'como_resolver' => 'Confira o regime especial no cadastro da empresa.'],

    // ---------- Tributos federais e valor aproximado dos tributos ----------
    'E0675' => ['acao' => 'emitente', 'campo' => 'pis_cofins_cst', 'titulo' => 'Tributos federais não se aplicam a pessoa física', 'aconteceu' => 'Emitente pessoa física (CPF) não informa tributos federais.', 'como_resolver' => 'Confira o cadastro da empresa.'],
    'E0676' => ['acao' => 'emitente', 'campo' => 'pis_cofins_cst', 'titulo' => 'MEI não informa tributos federais', 'aconteceu' => 'Prestador MEI não pode informar PIS/COFINS e outros tributos federais.', 'como_resolver' => 'Confira a situação no Simples Nacional no cadastro da empresa.'],
    'E0677' => ['acao' => 'emitente', 'campo' => 'pis_cofins_cst', 'titulo' => 'Base do PIS/COFINS maior que o valor do serviço', 'aconteceu' => 'A base de cálculo do PIS/COFINS não pode passar do valor do serviço.', 'como_resolver' => 'Confira as alíquotas e a situação tributária do PIS/COFINS no cadastro da empresa.'],
    'E0720' => ['acao' => 'emitente', 'campo' => 'pis_cofins_cst', 'titulo' => 'CSLL retida não permitida', 'aconteceu' => 'Com o tipo de retenção "PIS/COFINS/CSLL não retidos", a CSLL retida não pode ser informada.', 'como_resolver' => 'Ajuste o tipo de retenção do PIS/COFINS no cadastro da empresa.'],
    'E0724' => ['acao' => 'emitente', 'campo' => 'pis_cofins_cst', 'titulo' => 'Falta a CSLL retida', 'aconteceu' => 'Com este tipo de retenção, é obrigatório informar a CSLL retida.', 'como_resolver' => 'Ajuste o tipo de retenção do PIS/COFINS no cadastro da empresa ou use a nota completa personalizada para informar o valor.'],
    'E0702' => ['acao' => 'emitente', 'campo' => 'modo_tributos_aprox', 'titulo' => 'Valor aproximado dos tributos inválido', 'aconteceu' => 'O valor informado precisa ser maior ou igual a zero e não passar do valor do serviço.', 'como_resolver' => 'Confira a forma de informar o valor aproximado dos tributos no cadastro da empresa.'],
    'E0710' => ['acao' => 'emitente', 'campo' => 'modo_tributos_aprox', 'titulo' => 'MEI não informa a alíquota do Simples', 'aconteceu' => 'Prestador MEI não pode informar o percentual aproximado dos tributos pela alíquota do Simples.', 'como_resolver' => 'Escolha outra forma de informar o valor aproximado dos tributos no cadastro da empresa.'],
    'E0712' => ['acao' => 'emitente', 'campo' => 'modo_tributos_aprox', 'titulo' => 'Forma de informar tributos não vale para ME/EPP', 'aconteceu' => 'Para ME/EPP, esta forma de informar o valor aproximado dos tributos não pode ser usada.', 'como_resolver' => 'Escolha outra forma no cadastro da empresa.'],
    'E0713' => ['acao' => 'emitente', 'campo' => 'modo_tributos_aprox', 'titulo' => 'Forma de informar tributos não vale para não optante', 'aconteceu' => 'Para empresa não optante do Simples, a alíquota do Simples não pode ser usada como valor aproximado dos tributos.', 'como_resolver' => 'Escolha outra forma no cadastro da empresa.'],

    // ---------- Certificado digital ----------
    'E0714' => ['acao' => 'emitente', 'campo' => 'certificado', 'titulo' => 'Assinatura da nota recusada', 'aconteceu' => 'O arquivo foi enviado com erro na assinatura digital.', 'como_resolver' => 'Reenvie o certificado A1 no cadastro da empresa. Se continuar, o certificado pode estar vencido ou ser de outro CNPJ.'],
    'E0715' => ['acao' => 'emitente', 'campo' => 'certificado', 'titulo' => 'Certificado digital inválido', 'aconteceu' => 'A prefeitura não aceitou o certificado digital usado na assinatura.', 'como_resolver' => 'Confira a validade e o CNPJ do certificado e envie de novo no cadastro da empresa.'],
    'E0716' => ['acao' => 'emitente', 'campo' => 'certificado', 'titulo' => 'Certificado fora do padrão', 'aconteceu' => 'O certificado digital não segue o padrão exigido (precisa ser ICP-Brasil A1).', 'como_resolver' => 'Envie um certificado A1 válido no cadastro da empresa.'],
    'E0717' => ['acao' => 'emitente', 'campo' => 'certificado', 'titulo' => 'Nota sem assinatura', 'aconteceu' => 'A nota foi enviada sem assinatura digital.', 'como_resolver' => 'Confira se o certificado A1 está cadastrado na empresa e reenvie.'],

    // ---------- Cliente (tomador) ----------
    'E0188' => ['acao' => 'nota', 'campo' => 'tomador_documento', 'titulo' => 'CNPJ do cliente inválido', 'aconteceu' => 'O CNPJ do cliente não passou na conferência.', 'como_resolver' => 'Corrija o CNPJ do cliente abaixo e reenvie.'],
    'E0190' => ['acao' => 'nota', 'campo' => 'tomador_documento', 'titulo' => 'CNPJ do cliente não encontrado na Receita', 'aconteceu' => 'Esse CNPJ não existe no cadastro da Receita.', 'como_resolver' => 'Confira o número com o cliente e corrija abaixo.'],
    'E0194' => ['acao' => 'contador', 'campo' => 'tomador_documento', 'titulo' => 'Cliente sem estabelecimento no município', 'aconteceu' => 'O cliente, que é emitente da nota, não tem estabelecimento no município da prestação.', 'como_resolver' => 'Confirme os dados do cliente e o município da nota com a contabilidade.'],
    'E0202' => ['acao' => 'nota', 'campo' => 'tomador_documento', 'titulo' => 'Prestador e cliente são o mesmo CNPJ', 'aconteceu' => 'A empresa que emite não pode ser também o cliente da nota.', 'como_resolver' => 'Informe o CPF ou CNPJ do cliente correto abaixo.'],
    'E0204' => ['acao' => 'nota', 'campo' => 'tomador_documento', 'titulo' => 'Retenção do ISSQN exige CPF ou CNPJ do cliente', 'aconteceu' => 'A nota indica retenção do ISSQN pelo tomador, mas o cliente não foi identificado.', 'como_resolver' => 'Informe o CPF ou CNPJ do cliente abaixo.'],
    'E0206' => ['acao' => 'nota', 'campo' => 'tomador_documento', 'titulo' => 'CPF do cliente inválido', 'aconteceu' => 'O CPF do cliente não passou na conferência.', 'como_resolver' => 'Corrija o CPF do cliente abaixo e reenvie.'],
    'E0207' => ['acao' => 'nota', 'campo' => 'tomador_documento', 'titulo' => 'CPF do cliente não encontrado na Receita', 'aconteceu' => 'Esse CPF não existe no cadastro da Receita.', 'como_resolver' => 'Confira o número com o cliente e corrija abaixo.'],
    'E0234' => ['acao' => 'cliente', 'campo' => 'cep', 'titulo' => 'Falta o endereço do cliente', 'aconteceu' => 'Para esta nota a prefeitura exige o endereço do cliente.', 'como_resolver' => 'Complete o endereço no cadastro do cliente e reenvie.'],
    'E0235' => ['acao' => 'cliente', 'campo' => 'cep', 'titulo' => 'Falta o endereço do cliente', 'aconteceu' => 'Cliente identificado por CNPJ precisa ter o endereço nacional na nota.', 'como_resolver' => 'Complete o endereço no cadastro do cliente e reenvie.'],
    'E0237' => ['acao' => 'cliente', 'campo' => 'cep', 'titulo' => 'Retenção do ISSQN exige o endereço do cliente', 'aconteceu' => 'Quando o ISSQN é retido pelo tomador, o endereço do cliente é obrigatório.', 'como_resolver' => 'Complete o endereço no cadastro do cliente e reenvie.'],
    'E0238' => ['acao' => 'cliente', 'campo' => 'municipio', 'titulo' => 'Município do cliente não reconhecido', 'aconteceu' => 'O código do município do endereço do cliente não existe na tabela do IBGE.', 'como_resolver' => 'Escolha o município do cliente na lista, conforme a UF, e reenvie.'],
    'E0240' => ['acao' => 'cliente', 'campo' => 'cep', 'titulo' => 'CEP do cliente não confere com o município', 'aconteceu' => 'O CEP não existe ou não pertence ao município do endereço do cliente.', 'como_resolver' => 'Corrija o CEP (ou o município) no cadastro do cliente e reenvie.'],
    'E0247' => ['acao' => 'nota', 'campo' => 'tomador_email', 'titulo' => 'E-mail do cliente inválido', 'aconteceu' => 'O e-mail do cliente não está num formato aceito.', 'como_resolver' => 'Corrija o e-mail abaixo ou deixe em branco.'],
];

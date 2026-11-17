import csv, collections, os, re
# Uso: python docs/gerar-mapeamento-erros.py  (gera docs/mapeamento-erros-nfse.csv a partir de docs/regras-rejeicao-anexo-i.csv)
os.chdir(os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))

rows = list(csv.reader(open('docs/regras-rejeicao-anexo-i.csv', encoding='utf-8', errors='replace'), delimiter=';', quotechar='"'))[1:]

# ação -> descrição
ACOES = {
    'nota':     'Corrigir na nota (campo editável no painel Precisa de você)',
    'emitente': 'Corrigir no cadastro da empresa (Emitentes > Editar)',
    'cliente':  'Corrigir no cadastro do cliente (Tomadores)',
    'contador': 'Depende de cadastro na prefeitura: levar ao contador/prefeitura',
    'sistema':  'Falha do sistema: avisar o suporte e reenviar',
    'nao_suportado': 'Recurso que o sistema ainda não envia (não deveria ocorrer)',
}

def grupo(path):
    p = path.strip('/')
    if 'infDPS' in p:
        return p.split('infDPS', 1)[1].strip('/') or 'infDPS'
    return p

def classe(g, campo, codigo):
    """(acao, onde/campo da tela, orientação curta)"""
    # --- partes geradas pela SEFIN depois do envio: a pessoa não controla
    if g.startswith('NFSe') and not g.startswith('NFSe/infNFSe/DPS'):
        return 'sistema', 'Sistema / SEFIN', 'Validação feita pela SEFIN sobre a NFS-e gerada.'
    if g.startswith('NFSe/infNFSe/DPS'):
        if campo == 'Signature':
            return 'emitente', 'Emitentes > Certificado digital A1', 'Assinatura digital recusada: certificado vencido, inválido ou de outro CNPJ.'
        if 'BM' in g:
            return 'nao_suportado', 'Benefício municipal', 'Benefício municipal é bloqueado no Emissor Nacional e não é enviado.'
        if campo == 'versao':
            return 'sistema', 'Versão do leiaute (.env)', 'Versão do leiaute da DPS fora do aceito (1.00 ou 1.01).'
    # --- IBS/CBS (reforma tributária): não enviado
    if g.startswith('IBSCBS') or campo == 'IBSCBS':
        return 'nao_suportado', 'IBS/CBS', 'Grupo IBS/CBS bloqueado no Emissor Nacional; o sistema não envia.'
    # --- identificação da DPS
    if g == 'infDPS' or g == '':
        m = {
            'serie':   ('emitente', 'Emitentes > Série', 'Série fora da faixa (00001 a 49999) ou número já usado nessa série.'),
            'tpAmb':   ('emitente', 'Emitentes > Ambiente', 'Ambiente (teste/produção) incompatível com o emissor.'),
            'cLocEmi': ('emitente', 'Emitentes > Município', 'Município do emissor da DPS inválido ou sem convênio.'),
            'dCompet': ('nota', 'Data de competência', 'Data de competência inválida (futura, anterior ao cadastro ou fora do prazo).'),
            'dhEmi':   ('sistema', 'Data/hora do servidor', 'Data/hora de emissão fora do aceito: relógio do servidor.'),
            'id':      ('sistema', 'Identificador da DPS', 'Identificador da DPS não confere com os campos que o formam.'),
            'toma':    ('cliente', 'Tomadores > cadastro', 'Dados do tomador incompatíveis com o tipo de emissão.'),
        }
        if campo in m:
            return m[campo]
        return 'sistema', 'Cabeçalho da DPS', 'Campo de controle da DPS gerado pelo sistema.'
    # --- prestador
    if g.startswith('prest'):
        if campo in ('NIF', 'cNaoNIF') or 'endExt' in g or campo == 'endExt':
            return 'nao_suportado', 'Prestador no exterior', 'Prestador estrangeiro não é emitido por este sistema.'
        m = {
            'CNPJ': ('emitente', 'Emitentes > CNPJ (e certificado)', 'CNPJ do prestador inválido, não cadastrado ou diferente do certificado.'),
            'CPF':  ('emitente', 'Emitentes > CNPJ/CPF', 'CPF do prestador inválido ou não cadastrado.'),
            'IM':   ('contador', 'Emitentes > Inscrição municipal', 'A inscrição municipal precisa estar de acordo com o cadastro da empresa no CNC NFS-e do município.'),
            'email': ('sistema', 'E-mail do prestador', 'E-mail do prestador inválido (o sistema não envia).'),
            'xNome': ('sistema', 'Nome do prestador', 'O nome do prestador não vai na DPS (emitente = prestador).'),
            'end':  ('sistema', 'Endereço do prestador', 'O endereço do prestador não vai na DPS.'),
            'endNac': ('sistema', 'Endereço do prestador', 'O endereço do prestador não vai na DPS.'),
        }
        if g == 'prest/regTrib' or campo in ('opSimpNac', 'regApTribSN', 'regEspTrib'):
            return 'emitente', 'Emitentes > Como a empresa é tributada', 'Regime tributário (Simples/MEI/regime especial) incompatível com o cadastro da empresa no CNPJ.'
        if campo in m:
            return m[campo]
        return 'sistema', 'Prestador', 'Dado do prestador gerado pelo sistema.'
    # --- tomador
    if g.startswith('toma'):
        if campo in ('NIF', 'cNaoNIF') or 'endExt' in g or campo == 'endExt':
            return 'nao_suportado', 'Tomador no exterior', 'Tomador estrangeiro (exportação) ainda não é suportado.'
        m = {
            'CNPJ': ('nota', 'Nota > CPF ou CNPJ do cliente', 'CNPJ do cliente inválido, inexistente ou sem estabelecimento no município.'),
            'CPF':  ('nota', 'Nota > CPF ou CNPJ do cliente', 'CPF do cliente inválido ou inexistente.'),
            'IM':   ('sistema', 'Inscrição municipal do tomador', 'Inscrição municipal do tomador (o sistema não envia).'),
            'email': ('nota', 'Nota > E-mail do cliente', 'E-mail do cliente inválido.'),
            'xNome': ('nota', 'Nota > Nome do cliente', 'Nome do cliente ausente ou em formato inválido.'),
            'end':  ('cliente', 'Tomadores > Endereço', 'Endereço do cliente incompleto ou inválido.'),
            'endNac': ('cliente', 'Tomadores > Endereço', 'Endereço do cliente incompleto ou inválido (CEP, município, número).'),
            'CEP': ('cliente', 'Tomadores > CEP', 'CEP do cliente inválido.'),
            'cMun': ('cliente', 'Tomadores > Município', 'Município do cliente não reconhecido (código IBGE).'),
        }
        if campo in m:
            return m[campo]
        return 'cliente', 'Tomadores > cadastro', 'Dado do cliente inválido.'
    if g.startswith('interm'):
        return 'nao_suportado', 'Intermediário', 'Intermediário do serviço ainda não é enviado.'
    if g.startswith('subst'):
        return 'nao_suportado', 'Substituição de NFS-e', 'Substituição de NFS-e não é feita por este fluxo.'
    # --- serviço
    if g.startswith('serv'):
        if g.startswith('serv/cServ'):
            m = {
                'cTribNac': ('nota', 'Nota > Código de serviço', 'Código de serviço inexistente ou não administrado pela prefeitura da empresa.'),
                'cTribMun': ('emitente', 'Emitentes > Códigos complementares', 'Código complementar municipal inexistente ou não administrado pelo município.'),
                'cNBS':     ('nota', 'Nota > NBS', 'Código NBS inexistente ou que não combina com o serviço.'),
            }
            return m.get(campo, ('nota', 'Nota > Serviço', 'Dado do serviço inválido.'))
        if g.startswith('serv/locPrest'):
            return 'emitente', 'Emitentes > Local da prestação', 'Município do local da prestação inválido ou sem convênio.'
        return 'nao_suportado', 'Serviço especial', 'Exportação, obra, evento ou documento de referência: ainda não é enviado.'
    # --- valores
    if g.startswith('valores/vServPrest'):
        return 'nota', 'Nota > Valor', 'Valor do serviço inválido.'
    if g.startswith('valores/vDescCondIncond'):
        return 'sistema', 'Desconto', 'Descontos não são enviados.'
    if g.startswith('valores/vDedRed') or campo == 'vDedRed':
        if 'documentos' in g or campo == 'documentos':
            return 'nao_suportado', 'Dedução por documentos', 'Dedução com documentos não é enviada.'
        return 'emitente', 'Emitentes > Dedução/redução da base', 'Dedução/redução da base de cálculo incompatível com o regime ou o tipo de tributação.'
    if g.startswith('valores/trib/tribMun'):
        m = {
            'tribISSQN':  ('emitente', 'Emitentes > Tributação do ISSQN', 'Tributação do ISSQN incompatível com regime, local ou tipo de operação.'),
            'tpImunidade': ('emitente', 'Emitentes > Tributação do ISSQN', 'Tipo de imunidade incompatível.'),
            'tpRetISSQN': ('emitente', 'Emitentes > Retenção do ISSQN', 'Retenção do ISSQN incompatível com regime, local ou tomador.'),
            'pAliq':      ('emitente', 'Emitentes > Tributação do ISSQN', 'Alíquota do ISSQN incompatível com o regime (Simples/MEI) ou o município.'),
            'exigSusp':   ('nao_suportado', 'Exigibilidade suspensa', 'Exigibilidade suspensa é bloqueada e não é enviada.'),
            'cPaisResult': ('nao_suportado', 'Exportação', 'Resultado no exterior não é suportado.'),
        }
        return m.get(campo, ('emitente', 'Emitentes > Tributação do ISSQN', 'Tributação municipal incompatível.'))
    if g.startswith('valores/trib/tribFed'):
        return 'emitente', 'Emitentes > PIS/COFINS e retenções', 'PIS/COFINS ou retenção federal incompatível com o regime ou com os valores.'
    if g.startswith('valores/trib/tot'):
        return 'emitente', 'Emitentes > Valor aproximado dos tributos', 'Valor ou percentual aproximado dos tributos incompatível com a forma escolhida.'
    if g.startswith('valores'):
        return 'emitente', 'Emitentes > Tributação', 'Dado de tributação incompatível.'
    return 'sistema', 'Outros', 'Sem classificação específica.'

# prioridade: o que a pessoa pode realmente resolver e o sistema envia = alta
PRIORIDADE = {'nota': 'Alta', 'emitente': 'Alta', 'cliente': 'Alta', 'contador': 'Alta', 'sistema': 'Média', 'nao_suportado': 'Baixa'}

def tela(g, campo, acao):
    """Id do campo, na tela de destino, que corrige o erro (a tela rola até ele e o destaca)."""
    if g.startswith('prest') or g == 'NFSe/infNFSe/DPS' or g.startswith('infDPS') or g in ('infDPS', ''):
        m = {'CNPJ': 'cnpj', 'CPF': 'cnpj', 'IM': 'inscricao_municipal', 'opSimpNac': 'op_simples_nacional', 'regApTribSN': 'regime_apuracao_sn',
             'regEspTrib': 'regime_especial', 'Signature': 'certificado', 'serie': 'serie_dps', 'tpAmb': 'ambiente', 'cLocEmi': 'cidade-emitente'}
        return m.get(campo, '')
    if g.startswith('toma'):
        if acao == 'nota':
            return {'CNPJ': 'tomador_documento', 'CPF': 'tomador_documento', 'email': 'tomador_email', 'xNome': 'tomador_nome'}.get(campo, '')
        return {'CEP': 'cep', 'cMun': 'municipio'}.get(campo, 'cep')
    if g.startswith('serv'):
        if g.startswith('serv/cServ'):
            return {'cTribNac': 'codigo_servico', 'cNBS': 'codigo_nbs', 'cTribMun': 'lista-codigos'}.get(campo, '')
        if g.startswith('serv/locPrest'):
            return 'local_prestacao'
        return ''
    if g.startswith('valores/vServPrest'):
        return 'valor'
    if g.startswith('valores/vDedRed'):
        return 'deducao_tipo'
    if g.startswith('valores/trib/tribMun'):
        return {'tpRetISSQN': 'retencao_issqn'}.get(campo, 'trib_issqn')
    if g.startswith('valores/trib/tribFed'):
        return 'pis_cofins_cst'
    if g.startswith('valores/trib/tot'):
        return 'modo_tributos_aprox'
    if campo == 'dCompet':
        return 'data_competencia'
    return ''

saida = []
for r in rows:
    cod, path, campo, regra, aplic, efeito, msg = r
    g = grupo(path)
    acao, onde, orient = classe(g, campo, cod)
    campo_tela = 'data_competencia' if campo == 'dCompet' else tela(g, campo, acao)
    saida.append({
        'codigo': cod, 'grupo_xml': g, 'campo_xml': campo, 'acao': acao, 'onde_corrigir': onde,
        'campo_tela': campo_tela, 'orientacao_base': orient, 'prioridade': PRIORIDADE[acao], 'mensagem_oficial': re.sub(r'\s+', ' ', msg).strip()[:300],
    })

with open('docs/mapeamento-erros-nfse.csv', 'w', encoding='utf-8-sig', newline='') as f:
    w = csv.DictWriter(f, fieldnames=list(saida[0].keys()), delimiter=';')
    w.writeheader()
    w.writerows(saida)

cont = collections.Counter(x['acao'] for x in saida)
print(len(saida), dict(cont))
por_onde = collections.Counter((x['acao'], x['onde_corrigir']) for x in saida)
for (a, o), n in sorted(por_onde.items(), key=lambda t: (t[0][0], -t[1])):
    print(f'{a:14} {n:3}  {o}')


# ---- base de dados que o sistema lê (gerada; os textos próprios ficam em resources/data/erros-nfse.php) ----
def php(v):
    if isinstance(v, dict):
        return '[' + ', '.join(php(k) + ' => ' + php(x) for k, x in v.items()) + ']'
    return "'" + str(v).replace('\\', '\\\\').replace("'", "\\'") + "'"


os.makedirs('resources/data', exist_ok=True)
with open('resources/data/erros-nfse-base.php', 'w', encoding='utf-8', newline='\n') as f:
    f.write("<?php\n\n// GERADO por docs/gerar-mapeamento-erros.py a partir do Anexo I. Nao edite a mao: os textos proprios ficam em erros-nfse.php.\n\nreturn [\n")
    for x in saida:
        item = {'acao': x['acao'], 'onde': x['onde_corrigir'], 'orientacao': x['orientacao_base'], 'campo': x['campo_tela'],
                'xml': (x['grupo_xml'] + '/' + x['campo_xml']).strip('/'), 'oficial': x['mensagem_oficial']}
        f.write('    ' + php(x['codigo']) + ' => ' + php(item) + ",\n")
    f.write("];\n")

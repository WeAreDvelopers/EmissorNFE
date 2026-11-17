# Plano: orientar o usuário a corrigir cada erro da prefeitura

> **Status (17/11/2026):** fases 1, 2, 3 e a telemetria da fase 4 implementadas. Falta a **prevenção** da fase 4 (validar antes de enviar as combinações regime × tributação × retenção × dedução): hoje o sistema só bloqueia na tela o que já bloqueava antes.
> Onde está: catálogo em `resources/data/erros-nfse-base.php` (gerado) e `resources/data/erros-nfse.php` (textos próprios, 73 códigos), regra em `app/Support/CatalogoErros.php`, painel em `resources/views/notas/_painel.blade.php`, painel master em `Master > Erros da prefeitura`. Para um erro novo: acrescente uma linha em `erros-nfse.php`.

Objetivo: quando uma nota não sai, a pessoa entende **o que houve**, **onde corrigir** e **chega lá com um clique**, sem ler texto técnico.
Meta de cobertura: **100% dos códigos conhecidos com pelo menos a ação e o destino certos** (fase 1) e **texto próprio nos códigos que realmente acontecem** (fase 2 em diante).

Base do levantamento: `docs/regras-rejeicao-anexo-i.csv` (Anexo I, 429 códigos de rejeição).
Resultado do mapeamento: [`docs/mapeamento-erros-nfse.csv`](mapeamento-erros-nfse.csv) (um código por linha: grupo/campo do XML, ação, onde corrigir, orientação base, prioridade).

## 1. Situação hoje

- `app/Support/ExplicacaoErro.php` traduz 6 códigos (E0312, E0310, E0316, E0015, E0080/E0188/E0206, E0247) e os erros de XSD (separados em "Erro no sistema" e "Dado fora do formato").
- Todo o resto cai em: "A prefeitura recusou esta nota. Veja o motivo em Detalhes técnicos." com todos os campos da nota abertos para edição, o que muitas vezes é o lugar errado (ex.: o E0116 se resolve no cadastro da empresa, não na nota).

## 2. Mapeamento (o que o Anexo I mostra)

| Ação | Códigos | Onde a pessoa corrige |
|---|---:|---|
| **Corrigir no cadastro da empresa** | 99 | Emitentes > Editar (tributação do ISSQN 20, dedução 12, retenção do ISSQN 11, regime 10, PIS/COFINS 10, valor aproximado dos tributos 9, município 6, certificado 5, CNPJ/CPF 6, local da prestação 3, série 2, códigos complementares 2…) |
| **Corrigir no cadastro do cliente** | 7 | Tomadores (endereço, CEP, município) |
| **Corrigir na nota** | 29 | Painel "Precisa de você" (CPF/CNPJ, nome e e-mail do cliente, data de competência, valor, código de serviço, NBS) |
| **Depende da prefeitura/contador** | 4 | Inscrição municipal no CNC (E0116, E0119, E0120, E0124) |
| **Falha do sistema** | 131 | Validações da própria SEFIN sobre a NFS-e gerada, relógio, identificador: avisar o suporte e reenviar |
| **Recurso que o sistema não envia** | 159 | IBS/CBS (42), intermediário (25), dedução por documentos (25), serviço especial (23), substituição (17), benefício municipal (12), exterior (14)… Não deveria ocorrer: se ocorrer, é bug nosso |

Leitura: **135 códigos (31%) a própria pessoa resolve** (cadastro da empresa, do cliente ou a nota), 4 dependem de terceiros (prefeitura/contador) e os outros 290 são do sistema ou de recursos que não enviamos. O maior ganho está em levar a pessoa ao **cadastro da empresa** (99 códigos), hoje o destino menos indicado pelo painel.

Limites do levantamento: o Anexo I só lista rejeições da DPS e da NFS-e. Códigos de outros fluxos (cancelamento, eventos, certificado, comunicação) e códigos que o ADN criar depois aparecem como "sem tradução" e entram pela telemetria da fase 4.

## 3. Como vai funcionar

### 3.1 Catálogo de erros
Arquivo `resources/data/erros-nfse.php`, um item por código (gerado a partir do CSV de mapeamento e depois refinado à mão):

```php
'E0116' => [
    'acao'        => 'emitente',                    // nota | emitente | cliente | contador | sistema
    'titulo'      => 'Falta a inscrição municipal',
    'aconteceu'   => 'A prefeitura tem a sua empresa cadastrada com inscrição municipal e ela precisa ir na nota.',
    'como_resolver' => 'Informe a inscrição municipal no cadastro da empresa e reenvie.',
    'destino'     => ['rota' => 'emitentes.editar', 'campo' => 'inscricao_municipal'],
    'contador'    => 'Peça a inscrição municipal (CCM) da empresa no cadastro da prefeitura.',
],
```

Código sem texto próprio usa a **orientação base** do mapeamento (por grupo/campo do XML), então nenhum erro volta a ser genérico.

### 3.2 Resolver
`ExplicacaoErro` passa a extrair todos os códigos `E\d{4}` do texto técnico, escolher o item do catálogo (o mais específico primeiro) e juntar quando houver mais de um código na mesma nota (uma tela, vários passos).

### 3.3 Tela "Precisa de você"
- **Botão com destino**: "Corrigir no cadastro da empresa" abre Emitentes > Editar **já rolado e destacado no campo** (`#inscricao_municipal` + realce). Para cliente, abre o cadastro do tomador. Para nota, mostra só os campos relevantes (como hoje, mas filtrados pelo código, não todos).
- **Contador**: botão "Copiar mensagem para o contador" com texto pronto (código do erro, mensagem oficial e o que pedir).
- **Sistema**: texto claro (como já feito para XSD), número da nota e botão Reenviar.
- **Depois de corrigir o cadastro**: aviso "N notas aguardavam esta correção. Reenviar todas" (reenvio em lote, só das notas afetadas pelo mesmo código).

### 3.4 Prevenção (evitar a recusa)
Validar antes de enviar o que as regras permitem checar sem consultar a prefeitura, usando o próprio mapeamento: data de competência não futura, dígito verificador do CPF/CNPJ, série na faixa, número da DPS no formato, combinações regime × tributação × retenção × dedução, formato do código de serviço/NBS e de município. Cada regra nova reaproveita o texto do catálogo.

### 3.5 Telemetria e melhoria contínua
Tabela `erros_nfse_vistos` (código, ocorrências, última vez, tem tradução?). Painel simples para o administrador: **"Erros sem tradução, por frequência"**. Assim o catálogo cresce com o que acontece de verdade, não com o que imaginamos.

## 4. Fases

| Fase | Entrega | Resultado | Esforço (estimativa) |
|---|---|---|---|
| **1. Fundação** | Catálogo gerado do CSV + resolver + orientação base por grupo. Painel mostra ação certa e botão de destino (sem texto próprio ainda). | 100% dos códigos com ação e destino corretos. | 1 a 2 dias |
| **2. Textos dos que mais acontecem** | Texto próprio revisado para ~40 códigos: IM (E0116/E0119/E0120/E0124), código de serviço e complementar (E0310 a E0316), série e duplicidade (E0010, E0014), data (E0015, E0016, E0018, E1270), regime (E0160 a E0177), tributação/retenção/alíquota (E0529 a E0604), dedução (E0435 a E0454), PIS/COFINS (E0675 a E0724), tomador (E0188 a E0247) e assinatura/certificado (E0714 a E0717). | Mensagem e passo a passo claros nos casos reais. | 2 a 3 dias |
| **3. Atalhos de correção** | Deep link com destaque do campo, mensagem para o contador, reenvio em lote após corrigir o cadastro. | Corrigir e reenviar em poucos cliques. | 2 dias |
| **4. Prevenção + telemetria** | Validações antes do envio e painel "erros sem tradução". | Menos recusas e catálogo que se completa sozinho. | 2 a 3 dias |

Cada fase pode ir para produção sozinha.

## 5. Testes e critérios de aceite

- **Cobertura**: teste que lê o CSV de mapeamento e falha se algum código não tiver ação e orientação.
- **Catálogo**: teste que valida a estrutura de cada item (ação válida, rota existente, campo existente na tela de destino).
- **Casos reais**: um teste por código com texto próprio (entra o erro técnico, sai título, ação e destino).
- **Aceite**: para uma nota recusada com E0116, a pessoa vê "Falta a inscrição municipal", clica em "Corrigir no cadastro da empresa", cai no campo certo e reenvia, sem abrir "Detalhes técnicos".

## 6. Decisões necessárias

1. **Onde mora o catálogo**: arquivo no código (versionado, revisão por pull request) **ou** tabela editável por tela de administrador (ajuste de texto sem deploy). Recomendação: arquivo na fase 1 e tabela só se o volume de ajustes justificar.
2. **Texto para o contador**: revisar o modelo com a contabilidade (a quem ela encaminha, o que pede).
3. **Suporte**: canal para o caso "falha do sistema" (e-mail, WhatsApp ou formulário) para o botão de aviso.
4. **Prioridade do reenvio em lote**: entra na fase 3 ou antes (é o que mais poupa tempo quando muitas notas caem pelo mesmo motivo).

## 7. Riscos

- **Orientação errada é pior que genérica**: texto próprio só entra revisado; o que não está revisado usa a base conservadora.
- **Códigos que a prefeitura devolve em formato diferente do Anexo I**: a telemetria da fase 4 mostra rápido e o catálogo cobre.
- **Mudança do leiaute nacional**: o mapeamento é regenerável a partir do CSV do Anexo I (`docs/gerar-mapeamento-erros.py`), então uma nova versão do Anexo I atualiza a base sem retrabalho manual.

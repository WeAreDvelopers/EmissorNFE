# Planejamento: contratação, configuração e emissão de NFS-e pelo WhatsApp

Status: **planejamento (nenhum código escrito)**. Data: 10/11/2026.

## 1. Ideia em uma frase

O cliente descobre o número por compartilhamento ou divulgação, manda uma mensagem, o sistema reconhece se ele já é cliente e, se não for, conduz a contratação e a configuração da empresa por conversa. Depois disso, ele emite nota fiscal mandando mensagem.

## 2. Objetivos, fora do escopo e o que seria fracasso

**Objetivos**
- Do primeiro "oi" até a primeira nota emitida em poucos minutos e sem precisar abrir o site.
- Reconhecer o cliente automaticamente pelo número do WhatsApp.
- Emitir nota de forma simples (frase ou botões), sempre com confirmação antes de enviar.
- Reaproveitar o que já existe: contas, empresas, créditos, Pix, emissão, clientes, DANFSe.

**Fora do escopo (por enquanto)**
- Emissão de notas complexas (exportação, retenções federais, dedução) pelo chat: isso continua na "Nota fiscal completa personalizada" do site, aberta por link seguro enviado no WhatsApp.
- Atender município que não aderiu ao Emissor Nacional.
- Consultoria tributária: o sistema não aconselha, só aplica o que a empresa configurou.

**Seria fracasso, mesmo funcionando**
- Nota errada emitida por mal-entendido do chat (por isso toda emissão exige confirmação).
- Número bloqueado pela Meta por mau uso.
- Cliente sem certificado digital travado no meio e sem saída.
- Alguém emitir nota em nome de outra empresa só por usar o mesmo celular.

## 3. Como o cliente chega

- Link `wa.me/<número>?text=...` com texto já preenchido (ex.: "Quero emitir nota fiscal"), QR code em materiais e página de divulgação.
- **Código de origem** dentro do texto pré-preenchido (ex.: `REF-ABC123`), para saber quem indicou: revenda (o sistema já tem revendas) ou cliente que indicou outro. Serve para comissão e métricas.
- Cliente já cadastrado também pode compartilhar o contato do número com colegas: o código de indicação dele vai junto.
- Cuidado: número divulgado recebe lixo e curiosos. Previsto limite de mensagens por número desconhecido e resposta enxuta até a pessoa demonstrar interesse.

## 4. Reconhecimento: cliente ou não?

| Situação | Comportamento |
|---|---|
| Número já vinculado a um usuário | Cumprimenta pelo nome, mostra o menu curto ("Emitir nota", "Minhas notas", "Saldo"). |
| Número desconhecido | "Ainda não te conheço. Quer se cadastrar? [Sim] [Falar com atendente]". |
| Número de usuário com várias empresas | Pergunta qual empresa, com botões. |
| Número de usuário sem papel de emitir | Só consulta; emissão é recusada com explicação. |

O número do WhatsApp **não é senha**. Medidas: vínculo número-usuário só depois de uma confirmação inicial (código enviado por e-mail ou pelo link seguro), confirmação por botão em toda emissão, limite diário de notas e valor por número, e aviso ao dono da conta quando um número novo for autorizado.

## 5. Jornada de contratação (conversa)

Cada etapa fica salva, então o cliente pode parar e voltar do ponto em que estava.

1. **Boas-vindas e aceite.** Apresenta o serviço em 2 linhas, mostra link dos termos e da política de privacidade, botão "Aceito". Guardar prova do aceite (data, número, versão dos termos). Isso é exigência de LGPD e vira o contrato.
2. **CNPJ.** Cliente digita o CNPJ. O sistema consulta fontes públicas (razão social, endereço, município, atividade, optante do Simples/MEI) e mostra para confirmar. Já define regime, município e código IBGE.
3. **Município atendido?** Consulta a API de parâmetros municipais do nacional (convênio). Se o município não aderiu, informa com clareza e oferece avisar quando houver suporte.
4. **O que a empresa presta.** Cliente descreve em uma frase; o sistema sugere o código de serviço e o NBS (listas que já temos) e pede confirmação por lista. Define descrição padrão.
5. **Certificado digital A1 (etapa crítica).** Ver seção 8.
6. **Dados fiscais padrão.** O sistema propõe valores típicos para o regime (tributos aproximados, retenção "não", etc.) e o que depender de contador é marcado "confirmar com seu contador". Código complementar municipal: ver seção 9.
7. **Plano e pagamento.** Mostra pacotes (já existem pacotes de crédito), cliente escolhe, recebe **Pix copia e cola** na própria conversa. Quando o pagamento é confirmado (webhook que já existe), o sistema avisa.
8. **Nota de teste.** Empresa nasce em **ambiente de teste**. Cliente emite uma nota de teste guiada; ao dar certo, o sistema pergunta se quer ativar produção (regra que já existe no sistema).
9. **Pronto.** Resumo do que foi configurado e como emitir a primeira nota de verdade.

Exemplo de início:

> **Cliente:** Quero emitir nota fiscal
> **Sistema:** Oi! Ainda não encontrei seu cadastro. Posso te cadastrar agora, leva uns 5 minutos. [Quero me cadastrar] [Falar com uma pessoa]
> **Cliente:** (toca em "Quero me cadastrar")
> **Sistema:** Antes de começar, leia os termos: <link>. [Aceito] [Não aceito]
> **Sistema:** Qual é o CNPJ da empresa que vai emitir as notas?

## 5.1 Link para o contador responder o que o cliente não sabe

Em qualquer etapa que o cliente não souber responder (regime, código de serviço, NBS, regime especial, tributos aproximados, código complementar municipal, certificado), ele toca em **[Não sei, enviar para meu contador]**. O sistema gera um **link exclusivo** e uma mensagem pronta para ele encaminhar ao contador:

> "Oi, estou me cadastrando para emitir nota fiscal de serviço e preciso que você confirme algumas informações da minha empresa (leva uns 3 minutos): <link>"

**O que o contador vê** (página no celular, sem login e sem criar conta):
- O nome e o CNPJ da empresa e o que o sistema já preencheu sozinho.
- **Só as perguntas que ficaram pendentes**, com as mesmas listas do sistema (código de serviço, NBS, regimes) e textos de ajuda do tipo "veja no cartão CNPJ" ou "veja no DAS".
- Opção de **responder só uma parte**: o que ele deixar em branco continua pendente.
- Campo para **enviar o certificado digital** (A1) com a senha, no mesmo padrão seguro da seção 8. É comum o contador já ter o certificado do cliente, o que resolve o maior obstáculo do cadastro.
- Nome, CRC, e-mail e telefone do contador (opcionais, mas recomendados).

**Como o cliente acompanha e aprova**
- Ao receber as respostas, o sistema avisa o cliente no WhatsApp: "Seu contador respondeu: Simples Nacional, serviço 01.07.01... [Confirmar] [Corrigir]".
- As respostas ficam como **proposta** e só valem depois da confirmação do cliente, que continua responsável pela empresa. Fica registrado quem respondeu, quando e de onde.
- Se o contador demorar, o sistema lembra o cliente (dentro da janela de 24 h ou por mensagem-modelo). O sistema **não manda mensagem sozinho ao contador**, porque ele nunca deu consentimento: quem encaminha o link é o cliente.

**Segurança do link**
- Vale só para aquela empresa e aquelas perguntas, tem prazo (sugestão: 7 dias), pode ser cancelado pelo cliente e é guardado de forma que não dá para adivinhar.
- Mostra somente o necessário: nenhuma nota, cliente ou dado financeiro da empresa.
- Limite de tentativas e registro de acesso.

**Valor para o negócio**
- Reduz abandono: cliente que trava num termo fiscal tem uma saída em vez de desistir.
- Melhora a qualidade da configuração, porque quem responde é um profissional.
- Cria um **canal de contadores**: o mesmo link leva o contador a conhecer o sistema. Numa fase seguinte, o contador poderia ter um acesso próprio para acompanhar **várias empresas de seus clientes**, com indicação e comissão, como as revendas que o sistema já tem.

**Reaproveitamento:** os mesmos campos e regras do cadastro da empresa (código de serviço, NBS, tributos, códigos municipais), o mecanismo de convite por link que o sistema já usa, a auditoria e o upload seguro de certificado. A novidade é a página pública enxuta, a regra de "proposta que o cliente confirma" e o aviso por WhatsApp.

## 5.2 Contador com várias empresas (decidido)

**Decisões já tomadas:** o contador **pode enviar o certificado** do cliente, e o contador pode ter **várias empresas cadastradas e administrá-las** num só acesso.

**Como entra no modelo atual**
- O sistema já permite o mesmo usuário em várias contas, com um papel diferente em cada uma, troca de conta ativa e convite por link. O contador é, portanto, um usuário com acesso a várias contas.
- **Recomendação (minha escolha, a confirmar):** cada empresa cliente continua sendo **sua própria conta**, com o cliente como dono (assina os termos, tem seus créditos e seu histórico), e o contador entra como membro com um **novo papel "Contador"**. Isso preserva a responsabilidade do cliente e permite que ele retire o contador a qualquer momento. A alternativa, colocar todas as empresas dentro de uma conta do contador, mistura responsabilidade e dados e dificulta o cliente sair.

**Como o contador se vincula a uma empresa**
1. **Pelo link da seção 5.1:** ao responder, o contador cria um acesso (e-mail e senha, ou código no WhatsApp/e-mail) e, depois da confirmação do cliente, passa a ter a empresa na sua lista.
2. **Cadastrando por conta própria:** o contador cadastra a empresa do cliente (site ou WhatsApp) e **convida o cliente** para aceitar os termos e confirmar. Enquanto o cliente não aceitar, a empresa fica em teste e sem produção.
3. **Cliente convidando o contador:** pelo convite que já existe, com o novo papel.

**O que o contador faz**
- **Lista única de empresas** com situação de cada uma: configuração completa ou pendente, certificado e validade, créditos, notas do mês, notas recusadas e pendências.
- **Configurar a empresa**: padrões fiscais, códigos municipais, serviço e NBS, certificado (envio e troca), ambiente.
- **Emitir e consultar notas** de qualquer empresa, pelo site ou pelo WhatsApp: ao mandar mensagem, o sistema pergunta "qual empresa?".
- **Alertas:** certificado perto de vencer, nota recusada, crédito acabando, empresa travada em alguma etapa.

**Permissões sugeridas para o papel Contador** (a confirmar): configurar empresa e fiscal, enviar certificado, emitir, consultar, cancelar nota (**ato sem volta: decidir se exige confirmação do cliente**). Fica de fora por padrão: comprar créditos, gerenciar usuários e a própria conta (do cliente).

**Cuidados**
- **Certificado nas mãos do contador:** todo envio fica registrado (quem, quando, de onde) e o cliente é avisado. O cliente pode **revogar** o acesso do contador a qualquer momento, e o certificado só é usado para emitir notas daquela empresa (já fica criptografado).
- **Auditoria:** toda ação do contador na empresa entra na auditoria da conta do cliente, com o nome dele.
- **LGPD:** o contrato precisa prever o contador como parte que trata dados do cliente; termos próprios para o contador.
- **Troca de contador:** se o cliente trocar de contador, o acesso do antigo é removido e o do novo é convidado, sem perder histórico.

**Cobrança (decisão pendente):** quem paga os créditos? Opções: (a) cada cliente paga os seus; (b) o contador compra pacotes e distribui entre as empresas; (c) o contador paga tudo e repassa. Isso muda a tela de créditos e o contrato.

**Comercial:** o contador vira um **canal de venda** (indicação e comissão, como as revendas que o sistema já tem) e um forte motivo de retenção: quanto mais empresas ele administra, mais difícil sair.

## 6. Emissão pelo WhatsApp

**Duas formas, a mesma tela de confirmação**
- **Frase livre:** "Nota de 500 para Padaria Bom Grão, manutenção do sistema". Um modelo de linguagem extrai cliente, valor, descrição e data para um formato fixo. Ele **nunca emite**: só preenche.
- **Guiado:** perguntas curtas com botões e listas ("Para quem?" com os últimos clientes e "Outro").

**Confirmação obrigatória**

> **Sistema:** Confere? Nota de **R$ 500,00** para **Padaria Bom Grão** (CNPJ 12.345.678/0001-95), serviço "Manutenção de programas de computação", competência 10/11/2026. [Emitir] [Alterar] [Cancelar]

**Depois de emitir**
- Envia PDF do DANFSe e XML como documentos, com o número da nota.
- Se a SEFIN recusar, explica o motivo em português simples e o que fazer (o sistema já traduz os erros) e oferece corrigir pelo chat ou por link.
- Se o cliente final tem e-mail ou WhatsApp cadastrado, oferece enviar a nota a ele.

**Outras ações pelo chat:** consultar notas do mês, segunda via, cancelar nota, saldo de créditos, recarregar (Pix), cadastrar cliente (CNPJ com preenchimento automático e CEP), falar com atendente.
**Ações que abrem link seguro no navegador:** nota personalizada completa, trocar certificado, alterar padrões fiscais.

**Proteções:** cada mensagem recebida é processada uma só vez (evita nota duplicada se a Meta reenviar), o detector de nota parecida que já existe continua valendo, e há limite de valor e de quantidade por dia, configurável.

## 7. Arquitetura (visão geral, sem código)

- **Canal oficial:** WhatsApp Business Platform da Meta, via provedor homologado (BSP) ou direto. O número da API não pode ser usado ao mesmo tempo no aplicativo comum. Exige verificação da empresa na Meta e aprovação do nome de exibição.
- **Recebimento:** um endereço (webhook) que valida a assinatura da Meta, grava a mensagem e responde rápido; o processamento vai para a fila (a fila do sistema já existe).
- **Conversa como máquina de estados:** cada conversa guarda o passo atual e os dados já coletados, com validade. Mensagens recebidas e enviadas ficam registradas (auditoria, suporte, LGPD).
- **Módulos novos:** cliente do WhatsApp (enviar texto, botões, listas, documentos, mensagens-modelo), roteador de intenções (regras primeiro, modelo de linguagem só para entender frases), fluxos de cadastro, de emissão, de consulta e de pagamento.
- **O que se reaproveita:** contas, empresas e usuários, pacotes e créditos, Pix (Asaas/Inter), validação de CPF/CNPJ, busca de CEP, listas de serviços e NBS, emissão, DANFSe, tradução de erros, auditoria, painel master.
- **Refatoração necessária:** hoje as regras de emissão manual e de cadastro da empresa vivem dentro das telas (controllers). Precisam virar serviços únicos, usados pelo site e pelo WhatsApp, para não duplicar regra fiscal.
- **Janela de 24 horas:** a Meta só permite texto livre até 24 h depois da última mensagem do cliente. Fora disso, só **mensagens-modelo aprovadas** (por exemplo, "sua nota foi emitida", "seu pagamento foi confirmado"), com custo por mensagem. Os modelos precisam ser escritos e aprovados antes.
- **Painel de atendimento:** ver conversas, assumir a conversa (atendente humano) e devolver ao robô.

## 8. Certificado digital: o ponto mais difícil

A emissão pela API nacional exige certificado ICP-Brasil (A1). Isso é o maior obstáculo para o cadastro 100% por conversa.

- Quem **já tem** o arquivo (.pfx): o sistema envia um **link seguro** (endereço temporário e assinado, que abre bem no celular) para enviar o arquivo e digitar a senha. **A senha não deve trafegar no chat**, que fica guardado no aparelho e na Meta. O certificado é guardado criptografado (o sistema já faz isso).
- Quem **não tem**: o sistema explica o que é, quanto custa e oferece parceiro para emitir (videoconferência), voltando à conversa depois. Decisão comercial pendente.
- **MEI:** no portal nacional o MEI emite com login gov.br, sem certificado, mas a API exige certificado. Precisa de decisão: exigir certificado para MEI ou não atender MEI no início.
- Certificado em nuvem (A3 em nuvem) é uma alternativa futura; exige integração própria.

## 9. Pontos fiscais que exigem atenção

- **Código complementar municipal:** a lista é da prefeitura e não vem da API nacional. No cadastro por conversa, pedir que o cliente escolha de uma lista da empresa (cadastrada por nós para os municípios atendidos no início, por exemplo São Paulo) ou deixar o campo para uma segunda etapa assistida.
- **Padrões fiscais por regime:** proposta inicial revisada por um contador parceiro; o cliente vê o que foi definido e pode mudar pelo site.
- **Cancelamento:** regras de prazo e motivo do cancelamento valem também no chat.
- **Responsabilidade:** termos devem deixar claro que o conteúdo da nota é de responsabilidade do emitente e que o sistema não presta consultoria.

## 10. Segurança, LGPD e conformidade

- Aceite registrado, política de privacidade, base legal e canal para exclusão de dados.
- Mensagens guardadas com prazo de retenção definido; nada sensível (senha de certificado) no chat.
- Contrato com a Meta/provedor como operador de dados.
- Assinatura do webhook, limites por número, bloqueio de abuso, registro de tudo na auditoria.
- Modelo de linguagem: só dados mínimos, nunca decide emissão, custo e uso com teto diário.
- Política da Meta: contato ativo do sistema com o cliente só com consentimento e por modelo aprovado; qualidade ruim derruba o limite de envio.

## 11. Fases e entregas

**Fase 0: decisões e pré-requisitos (1 a 2 semanas)**
Provedor/número, verificação na Meta, nome de exibição, termos e contrato, preços e pacotes, estratégia de certificado, escolha do primeiro município. Teste técnico mínimo (receber e responder uma mensagem).

**Fase 1: base do canal**
Webhook, envio de mensagens, registro, máquina de conversa, reconhecimento por número, painel de atendimento e entrega para humano.

**Fase 2: contratação e configuração**
Aceite, CNPJ com consulta, verificação de município, link seguro de certificado, **link para o contador responder o que o cliente não sabe (seção 5.1)**, padrões fiscais, pacote e Pix, nota de teste, ativação de produção.

**Fase 2B: contador com várias empresas (seção 5.2)**
Papel "Contador" e permissões, lista consolidada de empresas com pendências e alertas, vínculo pelo link do contador, cadastro de empresa pelo contador com convite de aceite ao cliente, convite do cliente ao contador, revogação e troca de contador, auditoria das ações do contador e regra de cobrança escolhida. No WhatsApp: escolha da empresa antes de emitir. Pode andar em paralelo com a Fase 3.

**Fase 3: emissão pelo chat**
Fluxo guiado e frase livre, confirmação, envio de PDF/XML, tratamento de erro, cadastro de clientes pelo chat.

**Fase 4: completar**
Cancelar, consultar, segunda via, recarga, vários usuários e várias empresas, indicação e comissão, mensagens-modelo de aviso, áudio (transcrição).

**Fase 5: piloto e escala**
Piloto com 5 a 10 clientes reais em teste e depois em produção, revisão de segurança e LGPD, monitoramento, só então divulgação aberta.

## 12. Como medir

Funil: primeira mensagem → CNPJ → aceite → certificado → pagamento → primeira nota (taxa de cada passo e onde mais desistem); tempo até a primeira nota; notas emitidas por cliente por mês; pedidos de atendente humano; erros de emissão por motivo; custo por cliente ativo (Meta + modelo de linguagem); satisfação.

## 13. Riscos principais

| Risco | Efeito | Resposta |
|---|---|---|
| Cliente sem certificado | Cadastro trava | Parceiro de certificado e fluxo de retomada |
| Município sem convênio ou sem lista de códigos | Não consegue emitir | Verificação no início e lista de municípios atendidos |
| Número divulgado recebe abuso | Custo e queda de qualidade | Limites, resposta mínima a desconhecidos |
| Frase mal interpretada | Nota errada | Confirmação obrigatória e limites de valor |
| Uso indevido do celular | Nota em nome de outro | Vínculo confirmado, aviso ao dono, limites |
| Custos da Meta e do modelo de linguagem | Margem menor | Medir por cliente desde o piloto e ajustar pacotes |
| Dependência da Meta | Bloqueio ou mudança de regra | Provedor com suporte, plano B por site e e-mail |
| Suporte sobrecarregado | Experiência ruim | Painel com assunção de conversa e respostas prontas |
| Certificado em poder do contador | Uso indevido ou vazamento | Registro e aviso ao cliente a cada envio, uso só para a empresa, revogação a qualquer momento |
| Contador com muitas empresas emite na empresa errada | Nota no CNPJ errado | Escolha da empresa sempre explícita e mostrada no resumo de confirmação |
| Contador sai do cliente ou da plataforma | Cliente fica sem acesso ou configuração | Cliente é dono da conta, convida outro contador sem perder histórico |

## 14. Decisões que dependem do dono do produto

1. **Preço e pacotes**, e se haverá período de teste gratuito.
2. **Quem atender no lançamento:** só ME/EPP e não optante, ou MEI também (depende do certificado).
3. **Primeiro município** (sugestão: São Paulo, onde já há validação).
4. **Certificado:** parceiro para quem não tem? Quem paga?
5. **Provedor do WhatsApp** (direto na Meta ou BSP) e **nome e número** da marca.
6. **Quem faz o atendimento humano** e em que horários.
7. **Revendas:** cada revenda terá seu próprio número e marca, ou todas usam o número central com código de indicação?
8. **Contador parceiro** para revisar os padrões fiscais iniciais.
9. **Link do contador (decidido):** o contador pode enviar o certificado do cliente e ter várias empresas sob sua administração. **Ainda a confirmar:** as respostas dele valem só depois da confirmação do cliente (recomendado)?
10. **Contador, estrutura:** cada empresa como conta própria do cliente com o contador como membro (recomendado) ou tudo dentro da conta do contador?
11. **Contador, permissões:** o contador pode cancelar nota sozinho ou precisa da confirmação do cliente? Pode comprar créditos?
12. **Contador, cobrança e comissão:** quem paga os créditos (cliente, contador ou o contador repassa) e qual a comissão ou desconto para o contador parceiro?

## 15. Premissas que precisam ser confirmadas antes de implementar

- Preços, categorias de mensagem e limites da Meta mudam: conferir a tabela vigente na hora de contratar.
- Fontes públicas de consulta de CNPJ têm limites e podem mudar: definir fonte principal e reserva.
- Se a API de parâmetros municipais do nacional permite a consulta de convênio com o certificado da empresa em produção.

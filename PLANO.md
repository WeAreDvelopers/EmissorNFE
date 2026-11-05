# Plano de desenvolvimento — Emissor NFS-e SaaS

Passos independentes entre si, exceto onde há "depende de". Marque `[x]` ao concluir.

## Base
- [x] 0. Laravel 11 + overlay + MySQL (`nfse_saas`, porta 3308) + migrations + seed da revenda padrão
- [x] 1. **Autenticação** — Laravel Breeze (Blade): login, cadastro, logout. Fluxo: cadastro → `contas.criar`
- [x] 2. **Layout base** — menu, troca de conta no topo, saldo de créditos, mensagens `ok`/erros

## Telas (cada uma depende só do passo 1–2)
- [x] 3. `contas.criar` — criar conta (nome, CPF/CNPJ, e-mail financeiro)
- [x] 4. `emitentes.*` — lista e formulário (CNPJ, IM, município, regime, série, ambiente, upload do .pfx)
- [x] 5. `importacoes.*` — upload da planilha, lista, detalhe com progresso e reenvio
- [x] 6. `creditos.index` + `creditos.pix` — pacotes, extrato, QR gerado do copia-e-cola, consulta de status
- [x] 7. `usuarios.index` — usuários, papéis, convites (+ e-mail `ConviteConta`)

## Dados e operação
- [x] 8. Seed de desenvolvimento — pacotes de exemplo, usuário/conta/emitente de teste
- [x] 9. Gateway `fake` para Pix em desenvolvimento (sem Banco Inter)
- [ ] 10. Fila e agendador: `queue:work --queue=nfse,default`, `schedule:work`
- [x] 11. Planilha modelo para download (colunas esperadas por `NotasImport`)

## Integrações externas (dependem de credenciais)
- [ ] 12. Banco Inter sandbox: `.env` `INTER_*`, `php artisan inter:webhook`
- [ ] 13. SEFIN homologação com certificado A1 real; confirmar campo `dpsXmlGZipB64`
- [ ] 14. DANFSE próprio (NT 008/2026)

## Fases futuras
- [ ] 15. WhatsApp (fase 3)
- [ ] 16. Painel da revenda (fase 4)

## Notas de andamento
- Passos 1–9 e 11 feitos e cobertos por `php artisan test` (SQLite em memória; nunca aponta para o MySQL real).
- Passo 5: upload, listagem, progresso e reenvio prontos; a **emissão real** só roda com certificado A1 + SEFIN (passo 13).
- Passo 9: `SAAS_GATEWAY=fake` (só `APP_ENV=local/testing`); na tela do Pix há o botão "[desenvolvimento] Simular pagamento".
- Passo 10: processar a fila exige `php artisan queue:work --queue=nfse,default` (fila no banco) — ainda não deixado rodando.
- Login de teste (DevSeeder, só local): teste@local.test / senha-teste-123

## Redesenho aprovado (jornada simplificada) — aplicado
- Visual novo (verde, IBM Plex), menu Início · Importações · Créditos, selo Modo teste/Produção, textos em português.
- Importação em 3 passos: escolher → **conferir (sem gastar crédito)** → enviar. Nota válida nasce `rascunho`; só o envio reserva crédito.
- Correção na própria tela (linha inválida, recusada pela prefeitura, falha) e "Precisa de você" agrupado por causa, com reenvio em lote.
- Cadastro da empresa lê o certificado (CNPJ, razão social, validade). Cidade por autocomplete do IBGE (precisa de internet; **não testado**).
- Testes: `php artisan test` (37). **Não verificado no navegador** (extensão do Chrome não injetou scripts).
- Pendente: detalhe da nota (XML/DANFSE/histórico), nota de teste com 1 clique, e-mails (lote concluído, saldo baixo), upgrade do Laravel 11 (composer audit acusa avisos).

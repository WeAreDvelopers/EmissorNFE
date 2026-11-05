# XSD oficial da DPS — v1.01 (20/10/2026)

Cópia dos esquemas de `NFSe-ESQUEMAS_XSD-PRODREST-v1.01-20261020` (gov.br/nfse › Documentação Técnica, Homologação/testes).
Sem ajustes locais: o padrão da série (`TSSerieDPS`) já vem corrigido nesta versão.

Substitui o pacote `v1.01-20260209`. Mudanças que afetaram a DPS (ver `DpsBuilder`):

- `finNFSe` passou a ser obrigatório em `infDPS` (entre `tpEmit` e `cLocEmi`); valor `0` = NFS-e regular.
- `xDescServ` passou a ser o último elemento de `cServ` (após `cNBS` e `cIntContrib`).

Sem isso a SEFIN responde E1235 (falha no esquema XML).

Ao baixar uma versão nova, valide uma DPS gerada contra ela (`tests/Feature/DpsXsdTest.php`).

# XSD oficial da DPS — v1.01 (09/02/2026)

Cópia dos esquemas de `nfse-esquemas_xsd-v1-01-20260209.zip` (gov.br/nfse › Documentação Atual).

## Único ajuste local

`tiposSimples_v1.01.xsd`, tipo `TSSerieDPS`:

- oficial: `<xs:pattern value="^0{0,4}\d{1,5}$"/>`
- aqui:    `<xs:pattern value="0{0,4}\d{1,5}"/>`

Em XML Schema, os padrões já são ancorados e `^`/`$` são caracteres **literais**.
Com o original, o libxml (PHP) rejeita qualquer série — inclusive `00001`.
O ajuste não muda o que a série aceita, só faz a validação local funcionar.

Ao baixar uma versão nova do XSD, reaplique o ajuste se o padrão continuar igual.

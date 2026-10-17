# SchemaGuard

Editor visual de esquemas de banco de dados (alternativa ao dbdiagram.io) que também **compara versões, avisa de problemas e mostra o que uma mudança pode quebrar no código**.

> Desenhe. Compare. Entenda o impacto. Só então altere.

## O que faz hoje

- **Editor visual**: tabelas, colunas, tipos, chaves e relacionamentos; arraste as tabelas, **Espaço + arrastar** move o mapa; botão **Organizar** distribui as tabelas.
- **3 modos**: Iniciante (explica em linguagem simples), Intermediário (o essencial) e Avançado (índices, constraints e verificações detalhadas).
- **Importar SQL** (`CREATE TABLE`, `ALTER TABLE ... ADD`, `CREATE INDEX`, estilo PostgreSQL e MySQL).
- **Versões e comparação**: salve versões e veja o que mudou, com o risco de cada mudança (seguro, atenção, perigoso) e a **migração em SQL** sugerida. O SchemaGuard só mostra o SQL; nunca executa nada.
- **Verificação do esquema**: sem chave primária, relacionamentos prováveis ausentes, tipos incompatíveis, FK sem índice, colunas repetidas etc.
- **Análise de impacto (PHP)**: lê o SQL escrito no seu projeto PHP e mostra onde cada tabela e coluna é usada, e o que quebra ao apagar ou renomear.
- **Exportar**: SQL (PostgreSQL ou MySQL) e DBML.
- Desfazer/refazer, tema claro e escuro.

Tudo roda **localmente**: o projeto fica em arquivos JSON dentro de `projects/`, que o Git entende bem.

## Como rodar

Precisa de PHP 8.1 ou mais (sem dependências).

```
php -S localhost:8000 -t public
```

Abra http://localhost:8000.

## Estrutura

```
src/Model        modelo do esquema (Project, Table, Column, Relationship...)
src/Storage      salvar/carregar em JSON
src/Import       importador de SQL
src/Diff         comparação de versões (usa IDs estáveis: rename não vira "apagar + criar")
src/Sql          gerador de SQL
src/Validation   regras de verificação
src/Analyzer     análise de impacto no código (PHP)
src/Export       exportador de DBML
public           interface (HTML, CSS, JS) e a API em PHP
```

## Limitações conhecidas

- A análise de impacto só enxerga SQL escrito direto no PHP e chaves de array; ORMs (Eloquent etc.) e outras linguagens ainda não.
- A comparação ainda não cobre mudanças em chaves primárias, únicos e índices.
- Importar DBML ainda não existe (só exportar).

## Próximos passos

Analisadores para mais stacks (Laravel/Eloquent, JavaScript, Python), conexão direta com bancos reais e comparação com o diagrama, e integração visual com Git.

# Gestão Vinícola Brasília

Sistema de gestão em PHP + MySQL publicado em `public_html/gestao` (https://www.vinicolabrasilia.com.br/gestao).
Módulos: Tour, CRM (clientes, grupos de campanha, painel) e Centro de Distribuição.

## Deploy automático

Todo push na branch `main` dispara `.github/workflows/deploy.yml`, que:

1. confere a sintaxe de todos os arquivos PHP (com erro, nada é enviado);
2. envia os arquivos por FTP para `public_html/gestao`;
3. confere se a tela de login responde.

Também dá para rodar à mão em **Actions › Deploy Gestão Vinícola Brasília › Run workflow**.

### Secrets do repositório (Settings › Secrets and variables › Actions)

| Secret | Valor |
|---|---|
| `FTP_SERVER` | servidor FTP da HostGator |
| `FTP_USERNAME` | usuário completo da conta FTP (a conta abre em `public_html/gestao`) |
| `FTP_PASSWORD` | senha da conta FTP |

Variável opcional `FTP_PROTOCOL`: o padrão é `ftps` (senha criptografada). Se a HostGator recusar, crie a variável com o valor `ftp`.

### O que o deploy nunca toca

- `inc/config.php`: dados do banco e do e-mail. Existe só no servidor e não vai para o repositório.
- `uploads/comprovantes/` e `uploads/importacoes/`: arquivos enviados pelos usuários.
- `sql/`: histórico das migrações, aplicadas à mão no phpMyAdmin.

## Banco de dados

As migrações ficam em `sql/` e são rodadas à mão no phpMyAdmin, sempre depois de exportar um backup.
Já aplicadas em produção: até a v9. Pendente: v10 (Sugestões da IA: cartões de alerta, análises, datas comemorativas, configurações e perfis de gerente). Tarefas cron: ver ia_config.php.

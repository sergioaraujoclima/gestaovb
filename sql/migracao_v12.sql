-- Gestão Vinícola Brasília · migração v12 · campanhas por e-mail
-- Rode uma única vez no phpMyAdmin (aba SQL), depois da v11. Só acrescenta colunas; nenhum dado é apagado.
SET NAMES utf8mb4;

-- A mesma tabela guarda as campanhas de WhatsApp e de e-mail; "canal" separa as duas (cada grupo tem uma de cada).
ALTER TABLE crm_campanhas
  ADD COLUMN canal      ENUM('whatsapp','email') NOT NULL DEFAULT 'whatsapp' AFTER grupo_id,
  ADD COLUMN assunto    VARCHAR(200) NULL AFTER texto,
  ADD COLUMN preheader  VARCHAR(200) NULL AFTER assunto,
  ADD COLUMN html       MEDIUMTEXT   NULL AFTER preheader,
  ADD COLUMN botao_texto VARCHAR(80) NULL AFTER html,
  ADD COLUMN botao_link VARCHAR(300) NULL AFTER botao_texto,
  ADD COLUMN remetente  VARCHAR(80)  NULL AFTER botao_link,
  ADD COLUMN cor        VARCHAR(7)   NULL AFTER remetente,
  ADD COLUMN auto_envio TINYINT(1)   NOT NULL DEFAULT 0 AFTER cor,
  ADD INDEX idx_crm_campanhas_canal (grupo_id, canal, status);

-- Envios de e-mail: falhas, aberturas e cliques.
ALTER TABLE crm_campanha_envios
  MODIFY situacao ENUM('enviado','pulado','falhou') NOT NULL DEFAULT 'enviado',
  ADD COLUMN erro       VARCHAR(200) NULL,
  ADD COLUMN aberto_em  DATETIME     NULL,
  ADD COLUMN clicado_em DATETIME     NULL,
  ADD INDEX idx_crmenv_em (em);

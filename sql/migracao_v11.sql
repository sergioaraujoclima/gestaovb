-- Gestão Vinícola Brasília · migração v11 · mensagens de campanha dos grupos (WhatsApp)
-- Rode uma única vez no phpMyAdmin (aba SQL), depois da v10. Só cria tabelas novas.
SET NAMES utf8mb4;

-- Mensagem de campanha de um grupo: texto (com a formatação do WhatsApp e emojis), imagem opcional
-- e um cartão público (link com a imagem, que o WhatsApp mostra como prévia).
CREATE TABLE IF NOT EXISTS crm_campanhas (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  grupo_id       INT UNSIGNED NOT NULL,
  titulo         VARCHAR(120) NOT NULL,
  texto          TEXT         NULL,
  imagem         VARCHAR(80)  NULL,
  cartao         TINYINT(1)   NOT NULL DEFAULT 0,
  cartao_titulo  VARCHAR(120) NULL,
  cartao_texto   VARCHAR(300) NULL,
  area           ENUM('b2b','b2c','enoturismo') NOT NULL DEFAULT 'b2c',
  so_optin       TINYINT(1)   NOT NULL DEFAULT 0,
  status         ENUM('rascunho','liberada','encerrada') NOT NULL DEFAULT 'rascunho',
  inicio         DATE         NULL,
  fim            DATE         NULL,
  token          VARCHAR(16)  NOT NULL,
  criado_por     VARCHAR(120) NULL,
  criado_em      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em  DATETIME     NULL,
  liberada_por   VARCHAR(120) NULL,
  liberada_em    DATETIME     NULL,
  UNIQUE KEY uk_crm_campanhas_token (token),
  INDEX idx_crm_campanhas_grupo (grupo_id, status),
  CONSTRAINT fk_crmcamp_grupo FOREIGN KEY (grupo_id) REFERENCES crm_grupos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quem já recebeu (ou foi pulado) em cada campanha: o disparo continua de onde parou e não repete cliente.
CREATE TABLE IF NOT EXISTS crm_campanha_envios (
  campanha_id INT UNSIGNED NOT NULL,
  cliente_id  INT UNSIGNED NOT NULL,
  situacao    ENUM('enviado','pulado') NOT NULL DEFAULT 'enviado',
  em          DATETIME     NOT NULL,
  por         VARCHAR(120) NULL,
  PRIMARY KEY (campanha_id, cliente_id),
  INDEX idx_crmenv_cliente (cliente_id),
  CONSTRAINT fk_crmenv_campanha FOREIGN KEY (campanha_id) REFERENCES crm_campanhas(id) ON DELETE CASCADE,
  CONSTRAINT fk_crmenv_cliente  FOREIGN KEY (cliente_id)  REFERENCES clientes(id)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

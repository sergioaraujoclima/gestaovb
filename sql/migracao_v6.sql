-- Migração v6: cadastro de clientes do CRM (campos novos, etiquetas e importações).
-- Rode UMA vez no phpMyAdmin, no banco em uso (já na v5), DEPOIS de exportar um backup.
-- Só acrescenta tabelas e colunas opcionais: nenhum dado existente é alterado ou apagado.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS crm_importacoes (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  arquivo      VARCHAR(255) NOT NULL,
  usuario_id   INT UNSIGNED NULL,
  usuario_nome VARCHAR(120) NOT NULL,
  status       ENUM('concluida','desfeita') NOT NULL DEFAULT 'concluida',
  novos        INT UNSIGNED NOT NULL DEFAULT 0,
  atualizados  INT UNSIGNED NOT NULL DEFAULT 0,
  mesclados    INT UNSIGNED NOT NULL DEFAULT 0,
  ignorados    INT UNSIGNED NOT NULL DEFAULT 0,
  pendencias   INT UNSIGNED NOT NULL DEFAULT 0,
  criado_em    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  desfeita_em  DATETIME NULL,
  desfeita_por VARCHAR(120) NULL,
  CONSTRAINT fk_crmi_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE clientes
  ADD COLUMN rg               VARCHAR(30)  NULL AFTER cpf,
  ADD COLUMN telefone_e164    VARCHAR(20)  NULL AFTER telefone,
  ADD COLUMN endereco         VARCHAR(255) NULL AFTER data_nascimento,
  ADD COLUMN cep              VARCHAR(9)   NULL AFTER endereco,
  ADD COLUMN origem           VARCHAR(20)  NULL AFTER clube_beneficios,
  ADD COLUMN interesses       VARCHAR(500) NULL AFTER origem,
  ADD COLUMN ultima_interacao DATE         NULL AFTER interesses,
  ADD COLUMN pendencias       TEXT         NULL AFTER observacoes,
  ADD COLUMN importacao_id    INT UNSIGNED NULL AFTER pendencias,
  ADD INDEX idx_clientes_e164 (telefone_e164),
  ADD INDEX idx_clientes_email (email),
  ADD CONSTRAINT fk_clientes_importacao FOREIGN KEY (importacao_id) REFERENCES crm_importacoes(id) ON DELETE SET NULL;

-- Clientes que já existem vieram dos tours.
UPDATE clientes SET origem = 'tour' WHERE origem IS NULL;

CREATE TABLE IF NOT EXISTS crm_etiquetas (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome      VARCHAR(60) NOT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_crm_etiquetas_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_cliente_etiqueta (
  cliente_id  INT UNSIGNED NOT NULL,
  etiqueta_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (cliente_id, etiqueta_id),
  CONSTRAINT fk_crmce_cliente  FOREIGN KEY (cliente_id)  REFERENCES clientes(id)      ON DELETE CASCADE,
  CONSTRAINT fk_crmce_etiqueta FOREIGN KEY (etiqueta_id) REFERENCES crm_etiquetas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

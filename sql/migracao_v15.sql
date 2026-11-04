-- Gestão Vinícola Brasília · migração v15 · baixa de pagamentos das entregas do CD (perfil Financeiro)
-- Rode uma única vez no phpMyAdmin (aba SQL), depois da v14. Cria uma tabela nova e amplia um campo; nada é apagado.
SET NAMES utf8mb4;

-- Uma cobrança por vinícola e mês fechado: o que ela deve pelas entregas do mês e, depois, a baixa com o comprovante.
CREATE TABLE IF NOT EXISTS cd_cobrancas (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vinicola_id         INT UNSIGNED  NOT NULL,
  mes                 CHAR(7)       NOT NULL,            -- AAAA-MM (mês das entregas)
  qtd                 INT UNSIGNED  NOT NULL DEFAULT 0,
  total               DECIMAL(10,2) NOT NULL DEFAULT 0,  -- valor atual das entregas do mês
  vencimento          DATE          NULL,
  status              ENUM('aberta','paga') NOT NULL DEFAULT 'aberta',
  pago_em             DATE          NULL,
  valor_pago          DECIMAL(10,2) NULL,
  total_na_baixa      DECIMAL(10,2) NULL,                -- para avisar se entregas do mês mudarem depois da baixa
  comprovante         VARCHAR(80)   NULL,
  obs                 VARCHAR(500)  NULL,
  baixa_por           VARCHAR(120)  NULL,
  baixa_em            DATETIME      NULL,
  criado_em           DATETIME      NOT NULL,
  atualizado_em       DATETIME      NULL,
  UNIQUE KEY uk_cd_cobrancas (vinicola_id, mes),
  INDEX idx_cd_cobrancas_status (status, mes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Alertas da área Financeiro em Sugestões da IA.
ALTER TABLE ia_cartoes MODIFY area ENUM('geral','b2b','b2c','enoturismo','financeiro') NOT NULL;

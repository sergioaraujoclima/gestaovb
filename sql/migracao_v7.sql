-- Migração v7: pessoa jurídica, campos do ERP, sexo, consentimento de marketing e grupos de campanha.
-- Rode UMA vez no phpMyAdmin, no banco já na v6, DEPOIS de exportar um backup.
-- Só acrescenta colunas e tabelas: nenhum dado existente é alterado ou apagado.
SET NAMES utf8mb4;

ALTER TABLE clientes
  ADD COLUMN tipo_pessoa          ENUM('PF','PJ') NOT NULL DEFAULT 'PF' AFTER nome,
  ADD COLUMN cnpj                 VARCHAR(18)  NULL AFTER rg,
  ADD COLUMN ie                   VARCHAR(30)  NULL AFTER cnpj,
  ADD COLUMN im                   VARCHAR(30)  NULL AFTER ie,
  ADD COLUMN sexo                 ENUM('F','M') NULL AFTER im,
  ADD COLUMN sexo_presumido       TINYINT(1)   NOT NULL DEFAULT 0 AFTER sexo,
  ADD COLUMN telefone2            VARCHAR(30)  NULL AFTER telefone_e164,
  ADD COLUMN bairro               VARCHAR(100) NULL AFTER endereco,
  ADD COLUMN vendedor             VARCHAR(120) NULL AFTER interesses,
  ADD COLUMN cliente_desde        DATE         NULL AFTER vendedor,
  ADD COLUMN aceita_whatsapp      TINYINT(1)   NULL AFTER cliente_desde,
  ADD COLUMN aceita_email         TINYINT(1)   NULL AFTER aceita_whatsapp,
  ADD COLUMN consentimento_em     DATETIME     NULL AFTER aceita_email,
  ADD COLUMN consentimento_origem VARCHAR(60)  NULL AFTER consentimento_em,
  ADD INDEX idx_clientes_cnpj (cnpj),
  ADD INDEX idx_clientes_sexo (tipo_pessoa, sexo);

CREATE TABLE IF NOT EXISTS crm_grupos (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome           VARCHAR(80)  NOT NULL,
  descricao      VARCHAR(255) NULL,
  tipo           ENUM('dinamico','manual') NOT NULL DEFAULT 'dinamico',
  combinacao     ENUM('todas','qualquer')  NOT NULL DEFAULT 'todas',
  regras         TEXT NULL,
  ativo          TINYINT(1) NOT NULL DEFAULT 1,
  ordem          INT NOT NULL DEFAULT 100,
  criado_por     VARCHAR(120) NULL,
  criado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  recalculado_em DATETIME NULL,
  UNIQUE KEY uk_crm_grupos_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_grupo_membros (
  grupo_id     INT UNSIGNED NOT NULL,
  cliente_id   INT UNSIGNED NOT NULL,
  origem       ENUM('regra','manual','excluido') NOT NULL DEFAULT 'regra',
  incluido_em  DATETIME NOT NULL,
  incluido_por VARCHAR(120) NULL,
  PRIMARY KEY (grupo_id, cliente_id),
  INDEX idx_crmgm_cliente (cliente_id),
  CONSTRAINT fk_crmgm_grupo   FOREIGN KEY (grupo_id)   REFERENCES crm_grupos(id) ON DELETE CASCADE,
  CONSTRAINT fk_crmgm_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Grupos padrão (podem ser editados ou desativados na tela de Grupos).
INSERT INTO crm_grupos (nome, descricao, tipo, combinacao, regras, ordem, criado_por) VALUES
('Dia das Mães', 'Mulheres (pessoa física). Entram sozinhas ao cadastrar com sexo feminino.', 'dinamico', 'todas',
 '[{"campo":"tipo_pessoa","op":"=","valor":"PF"},{"campo":"sexo","op":"=","valor":"F"}]', 10, 'Sistema'),
('Dia dos Pais', 'Homens (pessoa física). Entram sozinhos ao cadastrar com sexo masculino.', 'dinamico', 'todas',
 '[{"campo":"tipo_pessoa","op":"=","valor":"PF"},{"campo":"sexo","op":"=","valor":"M"}]', 20, 'Sistema'),
('Clube de Benefícios', 'Membros do clube de benefícios.', 'dinamico', 'todas',
 '[{"campo":"clube","op":"=","valor":"1"}]', 30, 'Sistema'),
('Aniversariantes do mês', 'Pessoas físicas que fazem aniversário no mês corrente (atualiza sozinho a cada mês).', 'dinamico', 'todas',
 '[{"campo":"tipo_pessoa","op":"=","valor":"PF"},{"campo":"mes_aniversario","op":"=","valor":"atual"}]', 40, 'Sistema'),
('Empresas (corporativo)', 'Clientes pessoa jurídica: presentes de fim de ano, eventos corporativos.', 'dinamico', 'todas',
 '[{"campo":"tipo_pessoa","op":"=","valor":"PJ"}]', 50, 'Sistema'),
('Visitantes de tour', 'Quem já fez pelo menos um tour registrado no sistema.', 'dinamico', 'todas',
 '[{"campo":"tours","op":">=","valor":"1"}]', 60, 'Sistema'),
('Nunca fizeram tour', 'Pessoas físicas cadastradas sem tour registrado: convite para conhecer a vinícola.', 'dinamico', 'todas',
 '[{"campo":"tipo_pessoa","op":"=","valor":"PF"},{"campo":"estagio","op":"=","valor":"contato"}]', 70, 'Sistema'),
('Inativos (reativação)', 'Fizeram tour, mas o último foi há mais de 1 ano.', 'dinamico', 'todas',
 '[{"campo":"estagio","op":"=","valor":"inativo"}]', 80, 'Sistema');

-- Gestão Vinícola Brasília · migração v9 · módulo de Vendas (relatório de vendas por item do ERP)
-- Rode uma única vez no phpMyAdmin (aba SQL), depois da v8. Só cria tabelas novas e grupos novos:
-- não altera nem apaga nenhum dado existente.
SET NAMES utf8mb4;

-- Produtos: um por código do ERP. A importação cria e atualiza descrição, grupo, EAN e últimos preços;
-- o tipo (tinto, branco, espumante...) é sugerido na criação e pode ser corrigido na tela de produtos.
CREATE TABLE IF NOT EXISTS produtos (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo        VARCHAR(30)  NOT NULL,
  descricao     VARCHAR(200) NOT NULL,
  grupo         VARCHAR(80)  NULL,
  tipo          VARCHAR(20)  NOT NULL DEFAULT 'outro',
  ean           VARCHAR(40)  NULL,
  preco_venda   DECIMAL(10,2) NULL,
  preco_custo   DECIMAL(10,2) NULL,
  ativo         TINYINT(1)   NOT NULL DEFAULT 1,
  criado_em     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME     NULL,
  UNIQUE KEY uk_produtos_codigo (codigo),
  INDEX idx_produtos_tipo (tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vendas_importacoes (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  arquivo            VARCHAR(255) NOT NULL,
  usuario_id         INT UNSIGNED NULL,
  usuario_nome       VARCHAR(120) NOT NULL,
  periodo_ini        DATE NULL,
  periodo_fim        DATE NULL,
  status             ENUM('concluida','desfeita') NOT NULL DEFAULT 'concluida',
  vendas_novas       INT UNSIGNED NOT NULL DEFAULT 0,
  vendas_atualizadas INT UNSIGNED NOT NULL DEFAULT 0,
  vendas_iguais      INT UNSIGNED NOT NULL DEFAULT 0,
  itens              INT UNSIGNED NOT NULL DEFAULT 0,
  clientes_novos     INT UNSIGNED NOT NULL DEFAULT 0,
  produtos_novos     INT UNSIGNED NOT NULL DEFAULT 0,
  valor_total        DECIMAL(12,2) NOT NULL DEFAULT 0,
  crm_lote_id        INT UNSIGNED NULL,
  criado_em          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  desfeita_em        DATETIME NULL,
  desfeita_por       VARCHAR(120) NULL,
  CONSTRAINT fk_vimp_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_vimp_crm FOREIGN KEY (crm_lote_id) REFERENCES crm_importacoes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Uma venda por documento do ERP ("NF-e 2129", "Pedido Vendas 4958"...). Reimportar o mesmo documento atualiza, não duplica.
-- valor_total = líquido dos itens vendidos; bonificações e amostras (CFOP 5910/6910...) ficam em valor_bonificacao.
CREATE TABLE IF NOT EXISTS vendas (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  documento         VARCHAR(60)  NOT NULL,
  tipo_doc          VARCHAR(30)  NOT NULL,
  numero            VARCHAR(30)  NULL,
  canal             ENUM('loja','faturada') NOT NULL DEFAULT 'loja',
  data              DATETIME     NOT NULL,
  cliente_nome      VARCHAR(200) NULL,
  cliente_id        INT UNSIGNED NULL,
  vendedor          VARCHAR(120) NULL,
  pdv               VARCHAR(60)  NULL,
  deposito          VARCHAR(120) NULL,
  serie             VARCHAR(30)  NULL,
  chave_acesso      VARCHAR(60)  NULL,
  observacao        VARCHAR(500) NULL,
  itens_qtd         DECIMAL(12,3) NOT NULL DEFAULT 0,
  valor_bruto       DECIMAL(12,2) NOT NULL DEFAULT 0,
  desconto          DECIMAL(12,2) NOT NULL DEFAULT 0,
  acrescimo         DECIMAL(12,2) NOT NULL DEFAULT 0,
  valor_total       DECIMAL(12,2) NOT NULL DEFAULT 0,
  custo_total       DECIMAL(12,2) NOT NULL DEFAULT 0,
  lucro             DECIMAL(12,2) NOT NULL DEFAULT 0,
  valor_bonificacao DECIMAL(12,2) NOT NULL DEFAULT 0,
  custo_bonificacao DECIMAL(12,2) NOT NULL DEFAULT 0,
  hash              CHAR(32)     NOT NULL,
  importacao_id     INT UNSIGNED NULL,
  criado_em         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_vendas_documento (documento),
  INDEX idx_vendas_data (data),
  INDEX idx_vendas_cliente (cliente_id, data),
  INDEX idx_vendas_nome (cliente_nome),
  CONSTRAINT fk_vendas_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL,
  CONSTRAINT fk_vendas_importacao FOREIGN KEY (importacao_id) REFERENCES vendas_importacoes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS venda_itens (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  venda_id     INT UNSIGNED NOT NULL,
  produto_id   INT UNSIGNED NULL,
  descricao    VARCHAR(200) NOT NULL,
  cfop         VARCHAR(10)  NULL,
  bonificacao  TINYINT(1)   NOT NULL DEFAULT 0,
  quantidade   DECIMAL(12,3) NOT NULL DEFAULT 0,
  preco_unit   DECIMAL(12,2) NOT NULL DEFAULT 0,
  custo_total  DECIMAL(12,2) NOT NULL DEFAULT 0,
  desconto     DECIMAL(12,2) NOT NULL DEFAULT 0,
  acrescimo    DECIMAL(12,2) NOT NULL DEFAULT 0,
  bruto        DECIMAL(12,2) NOT NULL DEFAULT 0,
  liquido      DECIMAL(12,2) NOT NULL DEFAULT 0,
  lucro        DECIMAL(12,2) NOT NULL DEFAULT 0,
  INDEX idx_vitens_produto (produto_id),
  CONSTRAINT fk_vitens_venda   FOREIGN KEY (venda_id)   REFERENCES vendas(id)   ON DELETE CASCADE,
  CONSTRAINT fk_vitens_produto FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Memória do cruzamento: "este nome do ERP é este cliente" (ou "não vincular"). Vale para as próximas importações.
CREATE TABLE IF NOT EXISTS vendas_apelidos (
  chave       VARCHAR(200) NOT NULL PRIMARY KEY,
  nome_erp    VARCHAR(200) NOT NULL,
  cliente_id  INT UNSIGNED NULL,
  ignorar     TINYINT(1)   NOT NULL DEFAULT 0,
  definido_por VARCHAR(120) NULL,
  definido_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_vapel_cliente (cliente_id),
  CONSTRAINT fk_vapel_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Grupos de campanha pelas compras (se já existir um grupo com o mesmo nome, ele é mantido como está).
INSERT IGNORE INTO crm_grupos (nome, descricao, tipo, combinacao, regras, ordem, criado_por) VALUES
('Compradores', 'Clientes identificados em pelo menos uma venda do ERP.', 'dinamico', 'todas',
 '[{"campo":"compras","op":">=","valor":"1"}]', 90, 'Sistema'),
('Melhores clientes (R$ 2.000+)', 'Compraram R$ 2.000 ou mais no total. Atendimento VIP, lançamentos e pré-venda.', 'dinamico', 'todas',
 '[{"campo":"valor_compras","op":">=","valor":"2000"}]', 91, 'Sistema'),
('Compraram nos últimos 90 dias', 'Clientes ativos: última compra há até 90 dias.', 'dinamico', 'todas',
 '[{"campo":"compras","op":">=","valor":"1"},{"campo":"dias_ultima_compra","op":"<=","valor":"90"}]', 92, 'Sistema'),
('Sem recompra há mais de 90 dias', 'Já compraram, mas não voltam há mais de 90 dias: campanha de reativação.', 'dinamico', 'todas',
 '[{"campo":"compras","op":">=","valor":"1"},{"campo":"dias_ultima_compra","op":">=","valor":"91"}]', 93, 'Sistema'),
('Empresas compradoras (B2B)', 'Empresas que já compraram: tabela de revenda, reposição e datas comemorativas corporativas.', 'dinamico', 'todas',
 '[{"campo":"tipo_pessoa","op":"=","valor":"PJ"},{"campo":"compras","op":">=","valor":"1"}]', 94, 'Sistema'),
('Compradores de espumante', 'Já compraram espumante: Réveillon, casamentos e celebrações.', 'dinamico', 'todas',
 '[{"campo":"comprou_tipo","op":"tem","valor":"espumante"}]', 95, 'Sistema'),
('Compradores de branco e rosé', 'Já compraram vinho branco ou rosé: verão, Carnaval e harmonizações leves.', 'dinamico', 'qualquer',
 '[{"campo":"comprou_tipo","op":"tem","valor":"branco"},{"campo":"comprou_tipo","op":"tem","valor":"rose"}]', 96, 'Sistema');

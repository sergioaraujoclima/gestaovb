-- Migração v8: categorias de cliente (quem é o cliente: supermercado, restaurante, turista, consumidor...).
-- Rode UMA vez no phpMyAdmin, no banco já na v7, DEPOIS de exportar um backup.
-- Só acrescenta uma tabela e duas colunas opcionais: nenhum dado existente é alterado ou apagado.
-- Ao abrir o CRM pela primeira vez, o sistema sugere a categoria de cada cliente (marcada como "automática" para revisão).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS crm_categorias (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome        VARCHAR(60)  NOT NULL,
  descricao   VARCHAR(255) NULL,
  tipo_pessoa ENUM('PF','PJ','ambos') NOT NULL DEFAULT 'ambos',
  chave       VARCHAR(30)  NULL,
  ordem       INT NOT NULL DEFAULT 100,
  ativo       TINYINT(1) NOT NULL DEFAULT 1,
  criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_crm_categorias_nome (nome),
  UNIQUE KEY uk_crm_categorias_chave (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO crm_categorias (nome, descricao, tipo_pessoa, chave, ordem) VALUES
('Turista / visitante',      'Pessoa que conheceu a vinícola em tour ou visita.', 'PF', 'turista', 10),
('Consumidor final',         'Pessoa física que compra para consumo próprio.', 'PF', 'consumidor', 20),
('Supermercado / varejo',    'Supermercados, mercados, atacarejos e lojas de varejo.', 'PJ', 'supermercado', 30),
('Restaurante / bar',        'Restaurantes, bares, bistrôs, cafés e pizzarias.', 'PJ', 'restaurante', 40),
('Empório / adega',          'Empórios, adegas, lojas de vinho e delicatessens.', 'PJ', 'emporio', 50),
('Hotel / pousada',          'Hotéis, pousadas e hospedagens.', 'PJ', 'hotel', 60),
('Eventos / buffet',         'Buffets, cerimoniais e organizadores de eventos.', 'PJ', 'eventos', 70),
('Distribuidor / atacado',   'Distribuidores e revendas por atacado.', 'PJ', 'distribuidor', 80),
('Empresa (corporativo)',    'Demais empresas: brindes, presentes e eventos corporativos.', 'PJ', 'empresa', 90);

ALTER TABLE clientes
  ADD COLUMN categoria_id   INT UNSIGNED NULL AFTER tipo_pessoa,
  ADD COLUMN categoria_auto TINYINT(1) NOT NULL DEFAULT 0 AFTER categoria_id,
  ADD INDEX idx_clientes_categoria (categoria_id),
  ADD CONSTRAINT fk_clientes_categoria FOREIGN KEY (categoria_id) REFERENCES crm_categorias(id) ON DELETE SET NULL;

-- Limpeza: a coluna "Aniversário" do ERP trazia a data de cadastro para muitas pessoas (bebês de 1 ou 2 anos).
-- Pessoa física com menos de 12 anos tem o nascimento apagado e a data original guardada como pendência para conferência.
UPDATE clientes
   SET pendencias = CASE WHEN pendencias LIKE '%nascimento provavelmente errado%' THEN pendencias
                         ELSE CONCAT(COALESCE(CONCAT(pendencias, '\n'), ''), 'nascimento provavelmente errado (apagado): ', DATE_FORMAT(data_nascimento, '%d/%m/%Y')) END,
       data_nascimento = NULL
 WHERE tipo_pessoa = 'PF' AND data_nascimento > DATE_SUB(CURDATE(), INTERVAL 12 YEAR);

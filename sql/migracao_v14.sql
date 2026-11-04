-- Gestão Vinícola Brasília · migração v14 · histórico permanente das campanhas (métricas de efetividade)
-- Rode uma única vez no phpMyAdmin (aba SQL), depois da v13. Cria uma tabela nova e copia o histórico que já existe; nada é apagado.
SET NAMES utf8mb4;

-- Um registro por acontecimento: enviado, pulado, falhou, desfeito (WhatsApp marcado por engano), aberto, clicado,
-- descadastro e recadastro. Sem chaves estrangeiras de propósito: excluir grupo, campanha ou cliente não apaga a história.
-- Guarda o título da campanha e o nome do grupo do momento (o grupo pode ser renomeado ou excluído depois).
CREATE TABLE IF NOT EXISTS crm_campanha_eventos (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  campanha_id     INT UNSIGNED NOT NULL,
  grupo_id        INT UNSIGNED NULL,
  canal           ENUM('whatsapp','email') NOT NULL,
  campanha_titulo VARCHAR(120) NULL,
  grupo_nome      VARCHAR(80)  NULL,
  cliente_id      INT UNSIGNED NULL,
  evento          VARCHAR(20)  NOT NULL,
  em              DATETIME     NOT NULL,
  por             VARCHAR(120) NULL,
  detalhe         VARCHAR(200) NULL,
  INDEX idx_crmev_campanha (campanha_id, evento),
  INDEX idx_crmev_cliente (cliente_id, em),
  INDEX idx_crmev_em (em),
  INDEX idx_crmev_grupo (grupo_id, em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copia o que já aconteceu (envios, aberturas e cliques registrados até hoje).
INSERT INTO crm_campanha_eventos (campanha_id, grupo_id, canal, campanha_titulo, grupo_nome, cliente_id, evento, em, por, detalhe)
SELECT e.campanha_id, c.grupo_id, c.canal, c.titulo, g.nome, e.cliente_id, e.situacao, e.em, e.por, CONCAT('histórico anterior à v14', IF(e.erro IS NULL, '', CONCAT(' · ', LEFT(e.erro, 150))))
  FROM crm_campanha_envios e JOIN crm_campanhas c ON c.id = e.campanha_id LEFT JOIN crm_grupos g ON g.id = c.grupo_id;
INSERT INTO crm_campanha_eventos (campanha_id, grupo_id, canal, campanha_titulo, grupo_nome, cliente_id, evento, em, por, detalhe)
SELECT e.campanha_id, c.grupo_id, c.canal, c.titulo, g.nome, e.cliente_id, 'aberto', e.aberto_em, 'O próprio cliente', 'histórico anterior à v14'
  FROM crm_campanha_envios e JOIN crm_campanhas c ON c.id = e.campanha_id LEFT JOIN crm_grupos g ON g.id = c.grupo_id WHERE e.aberto_em IS NOT NULL;
INSERT INTO crm_campanha_eventos (campanha_id, grupo_id, canal, campanha_titulo, grupo_nome, cliente_id, evento, em, por, detalhe)
SELECT e.campanha_id, c.grupo_id, c.canal, c.titulo, g.nome, e.cliente_id, 'clicado', e.clicado_em, 'O próprio cliente', 'histórico anterior à v14'
  FROM crm_campanha_envios e JOIN crm_campanhas c ON c.id = e.campanha_id LEFT JOIN crm_grupos g ON g.id = c.grupo_id WHERE e.clicado_em IS NOT NULL;

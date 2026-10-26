-- Gestão Vinícola Brasília · migração v10 · módulo "Sugestões da IA"
-- Rode uma única vez no phpMyAdmin (aba SQL), depois da v9. Só cria tabelas novas e alarga a coluna de perfis
-- (para caber os novos perfis de gerente); nenhum dado existente é apagado.
SET NAMES utf8mb4;

-- Os perfis acumulados ficam na mesma coluna, separados por vírgula: com os gerentes, a lista pode ficar mais longa.
ALTER TABLE usuarios MODIFY perfil VARCHAR(255) NULL;

-- Configurações editáveis pela tela (chave da IA, mensagens padrão, link de avaliação...).
CREATE TABLE IF NOT EXISTS configuracoes (
  chave          VARCHAR(60)  NOT NULL PRIMARY KEY,
  valor          TEXT         NULL,
  atualizado_em  DATETIME     NULL,
  atualizado_por VARCHAR(120) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cartões de alerta e sugestão. "chave" evita repetir o mesmo alerta (ex.: tour_chegada:2026-10-26:10:00).
CREATE TABLE IF NOT EXISTS ia_cartoes (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chave         VARCHAR(120) NOT NULL,
  area          ENUM('geral','b2b','b2c','enoturismo') NOT NULL,
  tipo          VARCHAR(40)  NOT NULL,
  origem        ENUM('regra','ia') NOT NULL DEFAULT 'regra',
  prioridade    ENUM('alta','media','baixa') NOT NULL DEFAULT 'media',
  titulo        VARCHAR(200) NOT NULL,
  texto         TEXT         NULL,
  dados         MEDIUMTEXT   NULL,
  link          VARCHAR(255) NULL,
  quando        DATETIME     NULL,
  valido_ate    DATE         NOT NULL,
  status        ENUM('nova','andamento','feita','descartada') NOT NULL DEFAULT 'nova',
  comentario    VARCHAR(500) NULL,
  resolvido_por VARCHAR(120) NULL,
  resolvido_em  DATETIME     NULL,
  criado_em     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME     NULL,
  UNIQUE KEY uk_ia_cartoes_chave (chave),
  INDEX idx_ia_cartoes_area (area, status, valido_ate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Análise semanal escrita pela IA (uma por semana), com os números que foram enviados a ela.
CREATE TABLE IF NOT EXISTS ia_analises (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  semana     DATE         NOT NULL,
  modelo     VARCHAR(60)  NULL,
  resumo     TEXT         NULL,
  resposta   MEDIUMTEXT   NULL,
  dados      MEDIUMTEXT   NULL,
  tokens_in  INT UNSIGNED NULL,
  tokens_out INT UNSIGNED NULL,
  status     ENUM('ok','erro') NOT NULL DEFAULT 'ok',
  erro       VARCHAR(500) NULL,
  criado_por VARCHAR(120) NULL,
  criado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ia_analises_semana (semana)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Datas comemorativas e feriados que geram cartões de campanha com antecedência.
-- regra: 'fixa' (usa mes/dia), 'pascoa:N' (N dias depois da Páscoa; negativo = antes),
--        'nsemana:MES:DIA_SEMANA:N[:+D]' (N-ésimo dia da semana do mês, 0 = domingo; +D desloca dias).
CREATE TABLE IF NOT EXISTS ia_datas (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome         VARCHAR(100) NOT NULL,
  regra        VARCHAR(40)  NOT NULL DEFAULT 'fixa',
  mes          TINYINT UNSIGNED NULL,
  dia          TINYINT UNSIGNED NULL,
  area         ENUM('b2b','b2c','enoturismo') NOT NULL DEFAULT 'b2c',
  antecedencia SMALLINT UNSIGNED NOT NULL DEFAULT 21,
  grupo_nome   VARCHAR(80)  NULL,
  sugestao     VARCHAR(500) NULL,
  ativo        TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ia_datas (nome, regra, mes, dia, area, antecedencia, grupo_nome, sugestao) VALUES
('Carnaval', 'pascoa:-47', NULL, NULL, 'b2c', 21, 'Compradores de branco e rosé', 'Kit verão com brancos, rosés e espumantes gelados; destaque o Pilotis Rosé e o Cobogó Sauvignon Blanc.'),
('Dia do Consumidor', 'fixa', 3, 15, 'b2c', 14, 'Compradores', 'Condição especial por um dia para quem já comprou: frete grátis ou brinde na compra de 6 garrafas.'),
('Páscoa', 'pascoa:0', NULL, NULL, 'b2c', 21, 'Compradores', 'Harmonização de vinhos com chocolate; cestas de Páscoa com vinho.'),
('Aniversário de Brasília e Tiradentes', 'fixa', 4, 21, 'enoturismo', 21, 'Visitantes de tour', 'Feriado em Brasília: abra horários extras de tour e divulgue a experiência para o público local.'),
('Dia das Mães', 'nsemana:5:0:2', NULL, NULL, 'b2c', 30, 'Dia das Mães', 'Kit presente para mães (rosé ou espumante + taça), tour especial no fim de semana.'),
('Dia do Trabalho', 'fixa', 5, 1, 'enoturismo', 21, 'Visitantes de tour', 'Feriado: horários extras de tour e degustação.'),
('Dia do Vinho', 'nsemana:6:0:1', NULL, NULL, 'b2c', 21, 'Compradores', 'Campanha do Dia do Vinho: degustação na vinícola, condição especial na loja e posts nas redes.'),
('Corpus Christi', 'pascoa:60', NULL, NULL, 'enoturismo', 21, 'Visitantes de tour', 'Feriado prolongado: pacote de tour + almoço e divulgação para turistas.'),
('Dia dos Namorados', 'fixa', 6, 12, 'b2c', 21, 'Compradores de espumante', 'Kit casal com espumante e duas taças; jantar ou degustação a dois na vinícola.'),
('Dia do Amigo', 'fixa', 7, 20, 'b2c', 14, 'Compradores', 'Leve um amigo para o tour: segunda pessoa com desconto.'),
('Dia dos Pais', 'nsemana:8:0:2', NULL, NULL, 'b2c', 30, 'Dia dos Pais', 'Kit presente para pais (tinto encorpado, Monumental Syrah) e tour especial.'),
('Independência', 'fixa', 9, 7, 'enoturismo', 21, 'Visitantes de tour', 'Feriado: horários extras de tour.'),
('Dia do Cliente', 'fixa', 9, 15, 'b2c', 14, 'Compradores', 'Agradeça aos clientes com uma condição exclusiva para quem já comprou.'),
('Nossa Senhora Aparecida', 'fixa', 10, 12, 'enoturismo', 21, 'Visitantes de tour', 'Feriado: horários extras de tour e programação para famílias.'),
('Finados e Proclamação da República', 'fixa', 11, 2, 'enoturismo', 21, 'Visitantes de tour', 'Feriados de novembro: divulgue agenda de tours.'),
('Black Friday', 'nsemana:11:4:4:+1', NULL, NULL, 'b2c', 21, 'Compradores', 'Oferta de Black Friday para a base de compradores; antecipe para quem é recorrente.'),
('Natal corporativo', 'fixa', 12, 25, 'b2b', 60, 'Empresas compradoras (B2B)', 'Presentes corporativos de fim de ano: envie catálogo e tabela para empresas; feche pedidos até o início de dezembro.'),
('Natal', 'fixa', 12, 25, 'b2c', 30, 'Compradores', 'Cestas e kits de Natal; pedidos com entrega antes do dia 23.'),
('Réveillon', 'fixa', 12, 31, 'b2c', 30, 'Compradores de espumante', 'Espumantes para a virada: pré-venda para quem já comprou espumante.');

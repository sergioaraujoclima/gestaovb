-- Gestão Vinícola Brasília · migração v13 · calibragem do disparo das campanhas de e-mail
-- Rode uma única vez no phpMyAdmin (aba SQL), depois da v12. Só acrescenta colunas; nenhum dado é apagado.
SET NAMES utf8mb4;

-- Início do primeiro envio e intervalo (em minutos) entre um e-mail e o próximo.
ALTER TABLE crm_campanhas
  ADD COLUMN disparo_inicio    DATETIME          NULL AFTER auto_envio,
  ADD COLUMN disparo_intervalo SMALLINT UNSIGNED NULL AFTER disparo_inicio;

-- =============================================================
-- Achados Meli — campos do card de produto + dados de teste
-- (o site também cria estas colunas sozinho no primeiro acesso)
-- MariaDB / MySQL 8.0.29+: ADD COLUMN IF NOT EXISTS
-- =============================================================
SET NAMES utf8mb4;
ALTER TABLE products
  ADD COLUMN IF NOT EXISTS badge VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS brand VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS brand_verified INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS rating DECIMAL(2,1) NULL,
  ADD COLUMN IF NOT EXISTS sold_label VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS pix_price DECIMAL(12,2) NULL,
  ADD COLUMN IF NOT EXISTS installments_qty INT NULL,
  ADD COLUMN IF NOT EXISTS installments_value DECIMAL(12,2) NULL,
  ADD COLUMN IF NOT EXISTS installments_free INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS coupon_label VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS delivery_label VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS is_full INT NOT NULL DEFAULT 0;

-- Dados de teste dos cards
UPDATE products SET badge='BLACK FRIDAY', brand='MONDIAL', brand_verified=1, rating=NULL, sold_label='+100mil vendidos', pix_price=284.90, installments_qty=NULL, installments_value=NULL, installments_free=0, coupon_label=NULL, delivery_label='Chegará grátis amanhã', is_full=1 WHERE ml_id='MLB9000000001';
UPDATE products SET badge=NULL, brand='PHILCO', brand_verified=1, rating=4.7, sold_label='+5mil vendidos', pix_price=NULL, installments_qty=10, installments_value=34.90, installments_free=1, coupon_label=NULL, delivery_label='Frete grátis', is_full=0 WHERE ml_id='MLB9000000002';
UPDATE products SET badge=NULL, brand='TRAMONTINA', brand_verified=1, rating=4.9, sold_label='+10mil vendidos', pix_price=NULL, installments_qty=3, installments_value=29.97, installments_free=1, coupon_label=NULL, delivery_label=NULL, is_full=0 WHERE ml_id='MLB9000000003';
UPDATE products SET badge='OFERTA DO DIA', brand='OSTER', brand_verified=1, rating=4.6, sold_label='+1000 vendidos', pix_price=569.05, installments_qty=10, installments_value=59.90, installments_free=1, coupon_label='R$ 30 OFF com Cupom', delivery_label='Chegará grátis amanhã', is_full=1 WHERE ml_id='MLB9000000004';
UPDATE products SET badge='MAIS VENDIDO', brand='JBL', brand_verified=1, rating=4.8, sold_label='+50mil vendidos', pix_price=NULL, installments_qty=NULL, installments_value=NULL, installments_free=0, coupon_label='R$ 20 OFF com Cupom', delivery_label='Chegará grátis amanhã', is_full=1 WHERE ml_id='MLB9000000005';
UPDATE products SET badge=NULL, brand='AMAZFIT', brand_verified=1, rating=4.7, sold_label='+10mil vendidos', pix_price=360.05, installments_qty=NULL, installments_value=NULL, installments_free=0, coupon_label=NULL, delivery_label='Chegará grátis amanhã', is_full=1 WHERE ml_id='MLB9000000006';
UPDATE products SET badge='MAIS VENDIDO', brand='LOGITECH G', brand_verified=1, rating=4.9, sold_label='+100mil vendidos', pix_price=NULL, installments_qty=4, installments_value=27.48, installments_free=1, coupon_label=NULL, delivery_label='Chegará grátis amanhã', is_full=1 WHERE ml_id='MLB9000000007';
UPDATE products SET badge=NULL, brand=NULL, brand_verified=0, rating=4.5, sold_label='+500 vendidos', pix_price=NULL, installments_qty=6, installments_value=27.47, installments_free=0, coupon_label=NULL, delivery_label='Frete grátis', is_full=0 WHERE ml_id='MLB9000000008';
UPDATE products SET badge=NULL, brand=NULL, brand_verified=0, rating=4.6, sold_label='+25mil vendidos', pix_price=NULL, installments_qty=NULL, installments_value=NULL, installments_free=0, coupon_label=NULL, delivery_label=NULL, is_full=0 WHERE ml_id='MLB9000000009';
UPDATE products SET badge='BLACK FRIDAY', brand=NULL, brand_verified=0, rating=4.4, sold_label='+1000 vendidos', pix_price=NULL, installments_qty=4, installments_value=44.98, installments_free=1, coupon_label=NULL, delivery_label='Chegará grátis amanhã', is_full=1 WHERE ml_id='MLB9000000010';
UPDATE products SET badge=NULL, brand=NULL, brand_verified=0, rating=4.7, sold_label='+5mil vendidos', pix_price=NULL, installments_qty=NULL, installments_value=NULL, installments_free=0, coupon_label='R$ 5 OFF com Cupom', delivery_label='Frete grátis', is_full=0 WHERE ml_id='MLB9000000011';
UPDATE products SET badge=NULL, brand='TAIFF', brand_verified=1, rating=4.8, sold_label='+10mil vendidos', pix_price=179.55, installments_qty=NULL, installments_value=NULL, installments_free=0, coupon_label=NULL, delivery_label='Chegará grátis amanhã', is_full=1 WHERE ml_id='MLB9000000012';
UPDATE products SET badge=NULL, brand=NULL, brand_verified=0, rating=4.6, sold_label='+1000 vendidos', pix_price=NULL, installments_qty=5, installments_value=49.98, installments_free=1, coupon_label=NULL, delivery_label='Frete grátis', is_full=0 WHERE ml_id='MLB9000000013';
UPDATE products SET badge='OFERTA RELÂMPAGO', brand='XBOX', brand_verified=1, rating=4.9, sold_label='+10mil vendidos', pix_price=379.05, installments_qty=NULL, installments_value=NULL, installments_free=0, coupon_label=NULL, delivery_label='Chegará grátis amanhã', is_full=1 WHERE ml_id='MLB9000000014';
UPDATE products SET badge=NULL, brand='BOSCH', brand_verified=1, rating=4.8, sold_label='+5mil vendidos', pix_price=NULL, installments_qty=10, installments_value=27.90, installments_free=1, coupon_label=NULL, delivery_label='Frete grátis', is_full=0 WHERE ml_id='MLB9000000015';
UPDATE products SET badge=NULL, brand=NULL, brand_verified=0, rating=NULL, sold_label='+100 vendidos', pix_price=NULL, installments_qty=NULL, installments_value=NULL, installments_free=0, coupon_label=NULL, delivery_label=NULL, is_full=0 WHERE ml_id='MLB9000000016';

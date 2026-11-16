-- =============================================================
-- Achados Meli — estrutura do banco + produtos de TESTE
-- Gerado em 2026-11-16 16:28:25. Pode rodar mais de uma vez (não duplica).
-- =============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(255) PRIMARY KEY,
  v TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL UNIQUE,
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Produtos: affiliate_url guarda o link de afiliado (meli.la) de cada produto
CREATE TABLE IF NOT EXISTS products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ml_id VARCHAR(255) NOT NULL UNIQUE,
  title TEXT NOT NULL,
  slug VARCHAR(255) NOT NULL UNIQUE,
  price DECIMAL(12,2) NOT NULL DEFAULT 0,
  original_price DECIMAL(12,2) NULL,
  image TEXT,
  permalink TEXT,
  affiliate_url TEXT,
  category_id INT NULL,
  ml_category_id VARCHAR(255) NULL,
  free_shipping INT NOT NULL DEFAULT 0,
  item_condition VARCHAR(255) NULL,
  description TEXT,
  coupon VARCHAR(255) NULL,
  featured INT NOT NULL DEFAULT 0,
  active INT NOT NULL DEFAULT 1,
  clicks INT NOT NULL DEFAULT 0,
  posted_at VARCHAR(255) NULL,
  price_updated_at VARCHAR(255) NULL,
  created_at VARCHAR(255) NOT NULL,
  updated_at VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS price_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  price DECIMAL(12,2) NOT NULL,
  recorded_at VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Banners do carrossel da home (campanhas)
CREATE TABLE IF NOT EXISTS banners (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kicker VARCHAR(255) NULL,
  title VARCHAR(255) NULL,
  prefix VARCHAR(255) NULL,
  highlight VARCHAR(255) NULL,
  pill VARCHAR(255) NULL,
  footnote VARCHAR(255) NULL,
  image TEXT,
  link_url TEXT,
  sort_order INT NOT NULL DEFAULT 0,
  active INT NOT NULL DEFAULT 1,
  starts_at VARCHAR(255) NULL,
  ends_at VARCHAR(255) NULL,
  created_at VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Coleta automática: fontes (palavra-chave ou mais vendidos de uma categoria do ML) e histórico
CREATE TABLE IF NOT EXISTS ml_sources (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  type VARCHAR(255) NOT NULL DEFAULT 'keyword',
  query VARCHAR(255) NULL,
  ml_category_id VARCHAR(255) NULL,
  category_id INT NULL,
  min_discount INT NOT NULL DEFAULT 0,
  min_price DECIMAL(12,2) NULL,
  max_price DECIMAL(12,2) NULL,
  free_shipping_only INT NOT NULL DEFAULT 0,
  full_only INT NOT NULL DEFAULT 0,
  exclude_words TEXT NULL,
  max_items INT NOT NULL DEFAULT 20,
  auto_publish INT NOT NULL DEFAULT 0,
  every_hours INT NOT NULL DEFAULT 12,
  active INT NOT NULL DEFAULT 1,
  last_run_at VARCHAR(255) NULL,
  last_status VARCHAR(255) NULL,
  created_at VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ml_collect_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  source_id INT NULL,
  started_at VARCHAR(255) NOT NULL,
  finished_at VARCHAR(255) NULL,
  found INT NOT NULL DEFAULT 0,
  created INT NOT NULL DEFAULT 0,
  updated INT NOT NULL DEFAULT 0,
  skipped INT NOT NULL DEFAULT 0,
  errors TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Categorias
INSERT IGNORE INTO categories (name, slug, sort_order) VALUES ('Casa e Cozinha', 'casa-e-cozinha', 0);
INSERT IGNORE INTO categories (name, slug, sort_order) VALUES ('Tecnologia', 'tecnologia', 1);
INSERT IGNORE INTO categories (name, slug, sort_order) VALUES ('Moda', 'moda', 2);
INSERT IGNORE INTO categories (name, slug, sort_order) VALUES ('Beleza', 'beleza', 3);
INSERT IGNORE INTO categories (name, slug, sort_order) VALUES ('Esporte', 'esporte', 4);
INSERT IGNORE INTO categories (name, slug, sort_order) VALUES ('Games', 'games', 5);
INSERT IGNORE INTO categories (name, slug, sort_order) VALUES ('Bebês e Crianças', 'bebes-e-criancas', 6);
INSERT IGNORE INTO categories (name, slug, sort_order) VALUES ('Ferramentas', 'ferramentas', 7);
INSERT IGNORE INTO categories (name, slug, sort_order) VALUES ('Pet', 'pet', 8);
INSERT IGNORE INTO categories (name, slug, sort_order) VALUES ('Acessórios Automotivos', 'acessorios-automotivos', 9);

-- Produtos de teste (ml_id MLB90000000xx). Os botões levam para a busca do ML até você colar o link de afiliado.
INSERT IGNORE INTO products (ml_id,title,slug,price,original_price,image,permalink,affiliate_url,category_id,ml_category_id,free_shipping,item_condition,description,coupon,featured,active,clicks,price_updated_at,created_at,updated_at) VALUES ('MLB9000000001','Air Fryer Mondial 4L Family Preta Antiaderente','air-fryer-mondial-4l-family-preta-antiaderente',299.90,449.90,'https://placehold.co/600x600/ffe102/32346d/png?font=montserrat&text=Air%0AFryer%20Mondial','https://lista.mercadolivre.com.br/air-fryer-mondial-4l',NULL,(SELECT id FROM categories WHERE slug='casa-e-cozinha'),NULL,1,'new','Produto de TESTE — substitua por um produto real importado do Mercado Livre.',NULL,1,1,0,'2026-11-16 16:28:25','2026-11-16 16:28:25','2026-11-16 16:28:25');
INSERT IGNORE INTO products (ml_id,title,slug,price,original_price,image,permalink,affiliate_url,category_id,ml_category_id,free_shipping,item_condition,description,coupon,featured,active,clicks,price_updated_at,created_at,updated_at) VALUES ('MLB9000000002','Panela de Pressão Elétrica Philco 5L Inox','panela-de-pressao-eletrica-philco-5l-inox',349.00,499.00,'https://placehold.co/600x600/ffe102/32346d/png?font=montserrat&text=Panela%0Ade%20Press%C3%A3o','https://lista.mercadolivre.com.br/panela-de-pressao-eletrica-philco',NULL,(SELECT id FROM categories WHERE slug='casa-e-cozinha'),NULL,1,'new','Produto de TESTE — substitua por um produto real importado do Mercado Livre.',NULL,0,1,0,'2026-11-16 16:28:25','2026-11-16 16:28:25','2026-11-16 16:28:25');
INSERT IGNORE INTO products (ml_id,title,slug,price,original_price,image,permalink,affiliate_url,category_id,ml_category_id,free_shipping,item_condition,description,coupon,featured,active,clicks,price_updated_at,created_at,updated_at) VALUES ('MLB9000000003','Jogo de Facas Tramontina Plenus 6 Peças','jogo-de-facas-tramontina-plenus-6-pecas',89.90,129.90,'https://placehold.co/600x600/ffe102/32346d/png?font=montserrat&text=Jogo%0Ade%20Facas','https://lista.mercadolivre.com.br/jogo-de-facas-tramontina-plenus',NULL,(SELECT id FROM categories WHERE slug='casa-e-cozinha'),NULL,0,'new','Produto de TESTE — substitua por um produto real importado do Mercado Livre.',NULL,0,1,0,'2026-11-16 16:28:25','2026-11-16 16:28:25','2026-11-16 16:28:25');
INSERT IGNORE INTO products (ml_id,title,slug,price,original_price,image,permalink,affiliate_url,category_id,ml_category_id,free_shipping,item_condition,description,coupon,featured,active,clicks,price_updated_at,created_at,updated_at) VALUES ('MLB9000000004','Cafeteira Expresso Oster Primalatte 15 Bar','cafeteira-expresso-oster-primalatte-15-bar',599.00,899.00,'https://placehold.co/600x600/ffe102/32346d/png?font=montserrat&text=Cafeteira%0AExpresso%20Oster','https://lista.mercadolivre.com.br/cafeteira-oster-primalatte',NULL,(SELECT id FROM categories WHERE slug='casa-e-cozinha'),NULL,1,'new','Produto de TESTE — substitua por um produto real importado do Mercado Livre.','CAFE15',1,1,0,'2026-11-16 16:28:25','2026-11-16 16:28:25','2026-11-16 16:28:25');
INSERT IGNORE INTO products (ml_id,title,slug,price,original_price,image,permalink,affiliate_url,category_id,ml_category_id,free_shipping,item_condition,description,coupon,featured,active,clicks,price_updated_at,created_at,updated_at) VALUES ('MLB9000000005','Fone de Ouvido Bluetooth JBL Tune 520BT','fone-de-ouvido-bluetooth-jbl-tune-520bt',219.00,349.00,'https://placehold.co/600x600/ffe102/32346d/png?font=montserrat&text=Fone%0Ade%20Ouvido','https://lista.mercadolivre.com.br/jbl-tune-520bt',NULL,(SELECT id FROM categories WHERE slug='tecnologia'),NULL,1,'new','Produto de TESTE — substitua por um produto real importado do Mercado Livre.','ACHADO10',1,1,0,'2026-11-16 16:28:25','2026-11-16 16:28:25','2026-11-16 16:28:25');
INSERT IGNORE INTO products (ml_id,title,slug,price,original_price,image,permalink,affiliate_url,category_id,ml_category_id,free_shipping,item_condition,description,coupon,featured,active,clicks,price_updated_at,created_at,updated_at) VALUES ('MLB9000000006','Smartwatch Amazfit Bip 5 com GPS','smartwatch-amazfit-bip-5-com-gps',379.00,599.00,'https://placehold.co/600x600/ffe102/32346d/png?font=montserrat&text=Smartwatch%0AAmazfit%20Bip','https://lista.mercadolivre.com.br/amazfit-bip-5',NULL,(SELECT id FROM categories WHERE slug='tecnologia'),NULL,1,'new','Produto de TESTE — substitua por um produto real importado do Mercado Livre.',NULL,0,1,0,'2026-11-16 16:28:25','2026-11-16 16:28:25','2026-11-16 16:28:25');
INSERT IGNORE INTO products (ml_id,title,slug,price,original_price,image,permalink,affiliate_url,category_id,ml_category_id,free_shipping,item_condition,description,coupon,featured,active,clicks,price_updated_at,created_at,updated_at) VALUES ('MLB9000000007','Mouse Gamer Logitech G203 Lightsync RGB','mouse-gamer-logitech-g203-lightsync-rgb',109.90,189.90,'https://placehold.co/600x600/ffe102/32346d/png?font=montserrat&text=Mouse%0AGamer%20Logitech','https://lista.mercadolivre.com.br/logitech-g203',NULL,(SELECT id FROM categories WHERE slug='tecnologia'),NULL,1,'new','Produto de TESTE — substitua por um produto real importado do Mercado Livre.',NULL,0,1,0,'2026-11-16 16:28:25','2026-11-16 16:28:25','2026-11-16 16:28:25');
INSERT IGNORE INTO products (ml_id,title,slug,price,original_price,image,permalink,affiliate_url,category_id,ml_category_id,free_shipping,item_condition,description,coupon,featured,active,clicks,price_updated_at,created_at,updated_at) VALUES ('MLB9000000008','Caixa de Som Portátil Bluetooth 20W à Prova d''Água','caixa-de-som-portatil-bluetooth-20w-a-prova-d-agua',149.00,NULL,'https://placehold.co/600x600/ffe102/32346d/png?font=montserrat&text=Caixa%0Ade%20Som','https://lista.mercadolivre.com.br/caixa-de-som-bluetooth-20w',NULL,(SELECT id FROM categories WHERE slug='tecnologia'),NULL,1,'new','Produto de TESTE — substitua por um produto real importado do Mercado Livre.',NULL,0,1,0,'2026-11-16 16:28:25','2026-11-16 16:28:25','2026-11-16 16:28:25');
INSERT IGNORE INTO products (ml_id,title,slug,price,original_price,image,permalink,affiliate_url,category_id,ml_category_id,free_shipping,item_condition,description,coupon,featured,active,clicks,price_updated_at,created_at,updated_at) VALUES ('MLB9000000009','Kit 3 Camisetas Básicas 100% Algodão','kit-3-camisetas-basicas-100-algodao',89.90,NULL,'https://placehold.co/600x600/ffe102/32346d/png?font=montserrat&text=Kit%0A3%20Camisetas','https://lista.mercadolivre.com.br/kit-3-camisetas-basicas-algodao',NULL,(SELECT id FROM categories WHERE slug='moda'),NULL,0,'new','Produto de TESTE — substitua por um produto real importado do Mercado Livre.',NULL,0,1,0,'2026-11-16 16:28:25','2026-11-16 16:28:25','2026-11-16 16:28:25');
INSERT IGNORE INTO products (ml_id,title,slug,price,original_price,image,permalink,affiliate_url,category_id,ml_category_id,free_shipping,item_condition,description,coupon,featured,active,clicks,price_updated_at,created_at,updated_at) VALUES ('MLB9000000010','Tênis de Corrida Masculino Amortecimento','tenis-de-corrida-masculino-amortecimento',179.90,299.90,'https://placehold.co/600x600/ffe102/32346d/png?font=montserrat&text=T%C3%AAnis%0Ade%20Corrida','https://lista.mercadolivre.com.br/tenis-corrida-masculino',NULL,(SELECT id FROM categories WHERE slug='moda'),NULL,1,'new','Produto de TESTE — substitua por um produto real importado do Mercado Livre.',NULL,0,1,0,'2026-11-16 16:28:25','2026-11-16 16:28:25','2026-11-16 16:28:25');
INSERT IGNORE INTO products (ml_id,title,slug,price,original_price,image,permalink,affiliate_url,category_id,ml_category_id,free_shipping,item_condition,description,coupon,featured,active,clicks,price_updated_at,created_at,updated_at) VALUES ('MLB9000000011','Sérum Facial Vitamina C 30ml','serum-facial-vitamina-c-30ml',49.90,79.90,'https://placehold.co/600x600/ffe102/32346d/png?font=montserrat&text=S%C3%A9rum%0AFacial%20Vitamina','https://lista.mercadolivre.com.br/serum-vitamina-c',NULL,(SELECT id FROM categories WHERE slug='beleza'),NULL,1,'new','Produto de TESTE — substitua por um produto real importado do Mercado Livre.',NULL,0,1,0,'2026-11-16 16:28:25','2026-11-16 16:28:25','2026-11-16 16:28:25');
INSERT IGNORE INTO products (ml_id,title,slug,price,original_price,image,permalink,affiliate_url,category_id,ml_category_id,free_shipping,item_condition,description,coupon,featured,active,clicks,price_updated_at,created_at,updated_at) VALUES ('MLB9000000012','Secador de Cabelo Taiff 2000W Íon','secador-de-cabelo-taiff-2000w-ion',189.00,259.00,'https://placehold.co/600x600/ffe102/32346d/png?font=montserrat&text=Secador%0Ade%20Cabelo','https://lista.mercadolivre.com.br/secador-taiff-2000w',NULL,(SELECT id FROM categories WHERE slug='beleza'),NULL,1,'new','Produto de TESTE — substitua por um produto real importado do Mercado Livre.',NULL,0,1,0,'2026-11-16 16:28:25','2026-11-16 16:28:25','2026-11-16 16:28:25');
INSERT IGNORE INTO products (ml_id,title,slug,price,original_price,image,permalink,affiliate_url,category_id,ml_category_id,free_shipping,item_condition,description,coupon,featured,active,clicks,price_updated_at,created_at,updated_at) VALUES ('MLB9000000013','Kit Halteres Emborrachados 2 a 10kg','kit-halteres-emborrachados-2-a-10kg',249.90,329.90,'https://placehold.co/600x600/ffe102/32346d/png?font=montserrat&text=Kit%0AHalteres%20Emborrachados','https://lista.mercadolivre.com.br/kit-halteres-emborrachados',NULL,(SELECT id FROM categories WHERE slug='esporte'),NULL,1,'new','Produto de TESTE — substitua por um produto real importado do Mercado Livre.',NULL,0,1,0,'2026-11-16 16:28:25','2026-11-16 16:28:25','2026-11-16 16:28:25');
INSERT IGNORE INTO products (ml_id,title,slug,price,original_price,image,permalink,affiliate_url,category_id,ml_category_id,free_shipping,item_condition,description,coupon,featured,active,clicks,price_updated_at,created_at,updated_at) VALUES ('MLB9000000014','Controle Sem Fio Xbox Series Carbon Black','controle-sem-fio-xbox-series-carbon-black',399.00,499.00,'https://placehold.co/600x600/ffe102/32346d/png?font=montserrat&text=Controle%0ASem%20Fio','https://lista.mercadolivre.com.br/controle-xbox-series',NULL,(SELECT id FROM categories WHERE slug='games'),NULL,1,'new','Produto de TESTE — substitua por um produto real importado do Mercado Livre.','GAME20',0,1,0,'2026-11-16 16:28:25','2026-11-16 16:28:25','2026-11-16 16:28:25');
INSERT IGNORE INTO products (ml_id,title,slug,price,original_price,image,permalink,affiliate_url,category_id,ml_category_id,free_shipping,item_condition,description,coupon,featured,active,clicks,price_updated_at,created_at,updated_at) VALUES ('MLB9000000015','Furadeira de Impacto Bosch 650W com Maleta','furadeira-de-impacto-bosch-650w-com-maleta',279.00,399.00,'https://placehold.co/600x600/ffe102/32346d/png?font=montserrat&text=Furadeira%0Ade%20Impacto','https://lista.mercadolivre.com.br/furadeira-bosch-650w',NULL,(SELECT id FROM categories WHERE slug='ferramentas'),NULL,1,'new','Produto de TESTE — substitua por um produto real importado do Mercado Livre.',NULL,0,1,0,'2026-11-16 16:28:25','2026-11-16 16:28:25','2026-11-16 16:28:25');
INSERT IGNORE INTO products (ml_id,title,slug,price,original_price,image,permalink,affiliate_url,category_id,ml_category_id,free_shipping,item_condition,description,coupon,featured,active,clicks,price_updated_at,created_at,updated_at) VALUES ('MLB9000000016','Comedouro Automático para Pets 4L','comedouro-automatico-para-pets-4l',159.90,229.90,'https://placehold.co/600x600/ffe102/32346d/png?font=montserrat&text=Comedouro%0AAutom%C3%A1tico%20para','https://lista.mercadolivre.com.br/comedouro-automatico-pet',NULL,(SELECT id FROM categories WHERE slug='pet'),NULL,0,'new','Produto de TESTE — substitua por um produto real importado do Mercado Livre.',NULL,0,1,0,'2026-11-16 16:28:25','2026-11-16 16:28:25','2026-11-16 16:28:25');

-- Histórico de preço inicial dos produtos de teste
INSERT INTO price_history (product_id, price, recorded_at)
SELECT p.id, p.price, p.created_at FROM products p
WHERE p.ml_id LIKE 'MLB90000000%' AND NOT EXISTS (SELECT 1 FROM price_history h WHERE h.product_id = p.id);

-- Banners de teste
INSERT INTO banners (kicker,title,prefix,highlight,pill,footnote,image,link_url,sort_order,active,created_at) SELECT 'BLACK FRIDAY','Tecnologia no modo achado','ATÉ','40% OFF','Ver ofertas de tecnologia','*Preços conferidos diariamente. Podem mudar a qualquer momento.','assets/banners/tecnologia.svg','?c=tecnologia',1,1,NOW() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM banners WHERE kicker='BLACK FRIDAY' AND title='Tecnologia no modo achado');
INSERT INTO banners (kicker,title,prefix,highlight,pill,footnote,image,link_url,sort_order,active,created_at) SELECT 'CASA EM OFERTA','Cozinha completa gastando pouco','ATÉ','35% OFF','Ver achados de casa','*Ofertas sujeitas à disponibilidade no Mercado Livre.','assets/banners/cozinha.svg','?c=casa-e-cozinha',2,1,NOW() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM banners WHERE kicker='CASA EM OFERTA' AND title='Cozinha completa gastando pouco');
INSERT INTO banners (kicker,title,prefix,highlight,pill,footnote,image,link_url,sort_order,active,created_at) SELECT 'FRETE GRÁTIS','Achados que chegam sem custo de entrega',NULL,'R$ 0','Ver achados com frete grátis','*Frete grátis conforme regras do Mercado Livre para o seu CEP.','assets/banners/presentes.svg','?frete=1',3,1,NOW() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM banners WHERE kicker='FRETE GRÁTIS' AND title='Achados que chegam sem custo de entrega');

-- Banner com arte pronta (imagem inteira): Ferramentas
INSERT INTO banners (kicker,title,prefix,highlight,pill,footnote,image,link_url,sort_order,active,created_at) SELECT NULL,'Ofertas para ferramentas',NULL,NULL,NULL,NULL,'assets/banners/ferramentas.jpg','?c=ferramentas',0,1,NOW() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM banners WHERE image='assets/banners/ferramentas.jpg');

-- Para APAGAR os produtos de teste depois:
-- DELETE h FROM price_history h JOIN products p ON p.id = h.product_id WHERE p.ml_id LIKE 'MLB90000000%';
-- DELETE FROM products WHERE ml_id LIKE 'MLB90000000%';
-- DELETE FROM banners WHERE image LIKE 'assets/banners/%';

-- Dati di esempio per lo sviluppo locale (contenuti SEGNAPOSTO).
-- NON usare in produzione.
--
-- Utente backoffice di sviluppo:
--   username: admin
--   password: sv0Ujmq6SuTt

SET NAMES utf8mb4;

INSERT INTO user_group (id, name, deleted) VALUES (1, 'Casa Anto', 0)
  ON DUPLICATE KEY UPDATE name = VALUES(name);
DELETE FROM user WHERE username IN ('marco.yec', 'admin');
INSERT INTO user (name, surname, username, email, password, level, idUserGroup, avatar, deleted)
VALUES ('Admin', 'Casa Anto', 'admin', 'admin@casaanto.local', 'cfa1708d05da5804ae59656005c6e09747b682a6', 0, 1, 1, 0);

TRUNCATE shop_settings;
INSERT INTO shop_settings (id, shopName, notificationEmail, contactEmail, contactPhone, address, vatNumber, orderPrefix, reviewsAutoApprove, deleted)
VALUES (1, 'Casa Anto SRL', 'ordini@casaanto.local', 'info@casaanto.local', '+39 0438 000000', 'Vittorio Veneto (TV), Italia', 'IT00000000000', 'CA', 0, 0);

TRUNCATE shipping_zone;
INSERT INTO shipping_zone (name, name_en, countries, cost, freeThreshold, deliveryDays, deliveryDays_en, active, sortOrder, deleted) VALUES
('Italia', 'Italy', 'IT,SM,VA', 8.90, 80.00, 'In 48 ore', 'Within 48 hours', 1, 1, 0),
('Unione Europea', 'European Union', 'AT,BE,BG,CY,HR,DK,EE,FI,FR,DE,GR,IE,LV,LT,LU,MT,NL,PL,PT,CZ,RO,SK,SI,ES,SE,HU', 19.90, 150.00, '3-6 giorni lavorativi', '3-6 business days', 1, 2, 0);

-- Prodotto unico (linee guida v1.1). Il nome ufficiale è ancora da definire:
-- "Selezione Monte Pizzoc" è un segnaposto.
TRUNCATE product; TRUNCATE product_variant; TRUNCATE stock_movement;
INSERT INTO product (id, name_it, name_en, slug, subtitle_it, subtitle_en, description_it, description_en, tastingNotes_it, tastingNotes_en, pairings_it, pairings_en, cultivar, acidity, harvest, harvest_en, intensity, featured, active, sortOrder, deleted) VALUES
(1, 'Selezione Monte Pizzoc', 'Selezione Monte Pizzoc', 'selezione-monte-pizzoc',
 'Olio extravergine d''oliva · 100% Veneto', 'Extra virgin olive oil · 100% Veneto',
 'Ai piedi del Monte Pizzoc nasce Casa Anto. Un territorio in cui l''aria fresca di montagna incontra il clima più mite della pianura. Qui la maturazione è lenta e naturale.\n\nLe olive vengono raccolte a mano e lavorate con attenzione, seguendo tempi precisi e senza forzature.',
 'Casa Anto is born at the foot of Monte Pizzoc, where the fresh mountain air meets the milder climate of the plain. Here the olives ripen slowly and naturally.\n\nThey are picked by hand and processed with care, following precise timing and never forcing the process.',
 'Scheda tecnica in preparazione.', 'Technical sheet in preparation.',
 'Usi consigliati in preparazione.', 'Suggested uses in preparation.',
 NULL, NULL, 'Raccolto 2025', 'Harvest 2025', 'medio', 1, 1, 1, 0);

-- I testi definitivi del prodotto sono in custom/setup/product_content.sql

INSERT INTO product_variant (id, idProduct, format, sku, price, weightGrams, lowStockThreshold, active, sortOrder, fullName, deleted) VALUES
(1, 1, '0,5 L', 'CA-SMP-050', 50.00, 1000, 20, 1, 1, 'Selezione Monte Pizzoc 0,5 L', 0),
(2, 1, '1 L', 'CA-SMP-100', 85.00, 1800, 15, 1, 2, 'Selezione Monte Pizzoc 1 L', 0);

INSERT INTO stock_movement (idVariant, type, quantity, notes, deleted) VALUES
(1, 'carico', 300, 'Giacenza iniziale raccolto 2025', 0),
(2, 'carico', 150, 'Giacenza iniziale raccolto 2025', 0);

TRUNCATE discount_code;
INSERT INTO discount_code (code, description, type, value, minOrder, active, usedCount, deleted) VALUES
('BENVENUTO10', 'Sconto 10% di benvenuto', 'percentuale', 10, 30, 1, 0, 0),
('SPEDIZIONEGRATIS', 'Spedizione gratuita', 'spedizione_gratuita', 0, 0, 1, 0, 0);

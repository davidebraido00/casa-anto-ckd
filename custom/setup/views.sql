-- Viste del negozio Casa Anto. Da applicare dopo "Aggiorna il database":
--   mysql -u<user> -p <db> < custom/setup/views.sql
-- Idempotente (CREATE OR REPLACE).

-- Giacenza attuale per variante = somma dei movimenti di magazzino.
CREATE OR REPLACE VIEW gyv_stock AS
SELECT
    v.id,
    v.date,
    v.lastUpdate,
    0 AS deleted,
    v.idProduct,
    v.fullName,
    v.format,
    v.sku,
    v.price,
    COALESCE(s.stock, 0) AS stock,
    v.lowStockThreshold,
    CASE
        WHEN COALESCE(s.stock, 0) <= 0 THEN 'esaurito'
        WHEN COALESCE(s.stock, 0) <= COALESCE(v.lowStockThreshold, 0) THEN 'basso'
        ELSE 'ok'
    END AS stockStatus,
    ROUND(COALESCE(s.stock, 0) * v.price, 2) AS stockValue,
    CASE WHEN v.active = 1 AND p.active = 1 THEN 1 ELSE 0 END AS active
FROM product_variant v
JOIN product p ON p.id = v.idProduct AND p.deleted = 0
LEFT JOIN (
    SELECT idVariant, SUM(quantity) AS stock
    FROM stock_movement
    WHERE deleted = 0
    GROUP BY idVariant
) s ON s.idVariant = v.id
WHERE v.deleted = 0;

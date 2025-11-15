-- 20251105_add_vat_codes.sql
-- Aggiunge i codici IVA ai prodotti e memorizza il codice al momento della vendita.

SET @dbname := DATABASE();

-- Aggiunge la colonna vat_code alla tabella products se mancante.
SET @has_product_vat_code := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'vat_code'
);
SET @ddl := IF(
    @has_product_vat_code = 0,
    'ALTER TABLE products ADD COLUMN vat_code VARCHAR(32) NULL AFTER tax_rate',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Aggiunge la colonna vat_code alla tabella sale_items se mancante.
SET @has_sale_item_vat_code := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = 'sale_items'
      AND COLUMN_NAME = 'vat_code'
);
SET @ddl := IF(
    @has_sale_item_vat_code = 0,
    'ALTER TABLE sale_items ADD COLUMN vat_code VARCHAR(32) NULL AFTER tax_amount',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Allinea i codici IVA sulle righe di vendita storiche quando disponibile sul prodotto di origine.
UPDATE sale_items si
JOIN products p ON p.id = si.product_id
SET si.vat_code = p.vat_code
WHERE si.vat_code IS NULL
  AND p.vat_code IS NOT NULL;

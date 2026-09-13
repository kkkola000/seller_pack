-- Наличие показываем так, как его передал поставщик; статус «под заказ» убран.
-- Валюту можно задать в настройках источника.

ALTER TABLE products
    ADD COLUMN stock_text VARCHAR(190) NULL COMMENT 'Наличие в том виде, как его передал поставщик' AFTER stock_qty;

UPDATE products SET availability = 'out_of_stock' WHERE availability = 'on_order';

ALTER TABLE products
    MODIFY COLUMN availability ENUM('in_stock','out_of_stock') NOT NULL DEFAULT 'out_of_stock';

ALTER TABLE sources
    ADD COLUMN currency_code VARCHAR(16) NOT NULL DEFAULT '' COMMENT 'Валюта источника, если её нет в файле' AFTER price_multiplier;

-- Цена показывается так, как записана в прайсе; числовое значение остаётся для сортировки.

ALTER TABLE products
    ADD COLUMN price_text VARCHAR(190) NULL COMMENT 'Цена в том виде, как её передал поставщик' AFTER price;

-- Поставщики прячут строки и столбцы с неактуальными данными — по умолчанию их не импортируем.

ALTER TABLE sources
    ADD COLUMN skip_hidden TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Не импортировать скрытые строки и столбцы книги Excel' AFTER sheet_index;

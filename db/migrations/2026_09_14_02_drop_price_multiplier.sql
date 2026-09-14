-- Коэффициент к цене убран из интерфейса: цены берутся из прайса как есть.

ALTER TABLE sources DROP COLUMN price_multiplier;

-- Cercaviaggio - Manual provider interface support
-- Date: 2026-04-13
--
-- Adds integration mode + per-provider limits used by the backend "Integrazione" section.

ALTER TABLE cv_providers
  ADD COLUMN integration_mode ENUM('api','manual') NOT NULL DEFAULT 'api' AFTER api_key,
  ADD COLUMN manual_max_lines INT(10) UNSIGNED NOT NULL DEFAULT 0 AFTER integration_mode,
  ADD COLUMN manual_max_trips INT(10) UNSIGNED NOT NULL DEFAULT 0 AFTER manual_max_lines;


<?php
declare(strict_types=1);

if (!function_exists('cvPromotionsEnsureTable')) {
    function cvPromotionsEnsureTable(mysqli $connection): bool
    {
        static $initialized = null;
        if (is_bool($initialized)) {
            return $initialized;
        }

        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS cv_promotions (
  id_promo BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(190) NOT NULL,
  code VARCHAR(40) DEFAULT NULL,
  discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  mode ENUM('auto','code') NOT NULL DEFAULT 'code',
  visibility ENUM('public','hidden') NOT NULL DEFAULT 'hidden',
  provider_codes VARCHAR(255) NOT NULL DEFAULT '',
  days_of_week VARCHAR(20) NOT NULL DEFAULT '',
  valid_from DATETIME DEFAULT NULL,
  valid_to DATETIME DEFAULT NULL,
  priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  notes VARCHAR(255) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_promo),
  KEY idx_cv_promotions_active (is_active, mode, visibility, valid_from, valid_to, priority),
  KEY idx_cv_promotions_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

        $initialized = $connection->query($sql) === true;
        if (!$initialized) {
            error_log('cvPromotionsEnsureTable error: ' . $connection->error);
        }
        return $initialized;
    }
}

if (!function_exists('cvPromotionsNormalizeProviderCodes')) {
    /**
     * @param array<int|string,mixed>|string $value
     * @return array<int,string>
     */
    function cvPromotionsNormalizeProviderCodes($value): array
    {
        $rawItems = [];
        if (is_string($value)) {
            $rawItems = explode(',', $value);
        } elseif (is_array($value)) {
            $rawItems = $value;
        }

        $codes = [];
        foreach ($rawItems as $item) {
            $code = strtolower(trim((string) $item));
            if ($code === '') {
                continue;
            }
            $codes[$code] = $code;
        }
        ksort($codes);
        return array_values($codes);
    }
}

if (!function_exists('cvPromotionsProviderCodesToCsv')) {
    /**
     * @param array<int|string,mixed>|string $value
     */
    function cvPromotionsProviderCodesToCsv($value): string
    {
        return implode(',', cvPromotionsNormalizeProviderCodes($value));
    }
}

if (!function_exists('cvPromotionsNormalizeWeekdays')) {
    /**
     * 0=Sunday ... 6=Saturday
     * @param array<int|string,mixed>|string $value
     * @return array<int,int>
     */
    function cvPromotionsNormalizeWeekdays($value): array
    {
        $rawItems = [];
        if (is_string($value)) {
            $rawItems = explode(',', $value);
        } elseif (is_array($value)) {
            $rawItems = $value;
        }

        $days = [];
        foreach ($rawItems as $item) {
            if (!is_numeric($item)) {
                continue;
            }
            $day = (int) $item;
            if ($day < 0 || $day > 6) {
                continue;
            }
            $days[$day] = $day;
        }
        ksort($days);
        return array_values($days);
    }
}

if (!function_exists('cvPromotionsWeekdaysToCsv')) {
    /**
     * @param array<int|string,mixed>|string $value
     */
    function cvPromotionsWeekdaysToCsv($value): string
    {
        $days = cvPromotionsNormalizeWeekdays($value);
        return implode(',', array_map(static fn(int $day): string => (string) $day, $days));
    }
}

if (!function_exists('cvPromotionsTravelDateWeekday')) {
    function cvPromotionsTravelDateWeekday(string $travelDateIt): ?int
    {
        $travelDateIt = trim($travelDateIt);
        if ($travelDateIt === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('d/m/Y', $travelDateIt);
        if (!$date instanceof DateTimeImmutable) {
            return null;
        }
        return (int) $date->format('w');
    }
}


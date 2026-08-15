-- Tablas de Seguridad

CREATE TABLE IF NOT EXISTS `rate_limits` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `ip` VARCHAR(45) NOT NULL,
    `endpoint` VARCHAR(100) NOT NULL,
    `attempts` INT DEFAULT 1,
    `window_start` DATETIME NOT NULL,
    INDEX `idx_ip_endpoint` (`ip`, `endpoint`),
    INDEX `idx_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `ip_address` VARCHAR(45) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `attempted_at` DATETIME NOT NULL,
    INDEX `idx_ip` (`ip_address`),
    INDEX `idx_attempted` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

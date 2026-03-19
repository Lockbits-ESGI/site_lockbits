CREATE DATABASE IF NOT EXISTS lockbits_client CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE lockbits_client;

-- =========================================================
-- CORE APP TABLES
-- =========================================================

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(180) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tickets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    subject VARCHAR(180) NOT NULL,
    status ENUM('open', 'in_progress', 'closed') NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tickets_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB;

-- =========================================================
-- ORGANIZATION (1 admin user per organization)
-- =========================================================

CREATE TABLE IF NOT EXISTS organizations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    slug VARCHAR(160) NOT NULL UNIQUE,
    admin_user_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_organizations_admin_user
        FOREIGN KEY (admin_user_id) REFERENCES users(id)
        ON DELETE RESTRICT
) ENGINE=InnoDB;

-- =========================================================
-- EDR (only requested collected information)
-- Categories:
--  - Processus
--  - Reseau
--  - Fichiers suspects
--  - Utilisateurs connectes
--  - Systeme
-- =========================================================

CREATE TABLE IF NOT EXISTS edr_endpoints (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id INT UNSIGNED NOT NULL,
    endpoint_uuid CHAR(36) NOT NULL,
    hostname VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NULL,
    CONSTRAINT fk_edr_endpoint_org
        FOREIGN KEY (organization_id) REFERENCES organizations(id)
        ON DELETE CASCADE,
    UNIQUE KEY uk_edr_endpoint_uuid (endpoint_uuid),
    KEY idx_edr_endpoint_org (organization_id),
    KEY idx_edr_endpoint_last_seen (last_seen_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS edr_reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    endpoint_id BIGINT UNSIGNED NOT NULL,
    reported_at DATETIME NOT NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    raw_payload JSON NULL,
    CONSTRAINT fk_edr_report_endpoint
        FOREIGN KEY (endpoint_id) REFERENCES edr_endpoints(id)
        ON DELETE CASCADE,
    KEY idx_edr_reports_endpoint_time (endpoint_id, reported_at)
) ENGINE=InnoDB;

-- Processus: PID, nom, CPU%, RAM%, chemin executable
CREATE TABLE IF NOT EXISTS edr_report_processes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id BIGINT UNSIGNED NOT NULL,
    pid INT UNSIGNED NOT NULL,
    process_name VARCHAR(255) NOT NULL,
    cpu_percent DECIMAL(5,2) NULL,
    ram_percent DECIMAL(5,2) NULL,
    executable_path VARCHAR(1024) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_edr_processes_report
        FOREIGN KEY (report_id) REFERENCES edr_reports(id)
        ON DELETE CASCADE,
    KEY idx_edr_processes_report (report_id),
    KEY idx_edr_processes_pid (pid)
) ENGINE=InnoDB;

-- Reseau: connexions actives, ports ouverts, adresses distantes
CREATE TABLE IF NOT EXISTS edr_report_network (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id BIGINT UNSIGNED NOT NULL,
    protocol VARCHAR(20) NULL,
    local_address VARCHAR(255) NULL,
    local_port INT UNSIGNED NULL,
    remote_address VARCHAR(255) NULL,
    remote_port INT UNSIGNED NULL,
    connection_state VARCHAR(50) NULL,
    is_open_port TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_edr_network_report
        FOREIGN KEY (report_id) REFERENCES edr_reports(id)
        ON DELETE CASCADE,
    KEY idx_edr_network_report (report_id),
    KEY idx_edr_network_remote (remote_address)
) ENGINE=InnoDB;

-- Fichiers suspects: nouveaux fichiers dans repertoires sensibles
CREATE TABLE IF NOT EXISTS edr_report_suspicious_files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id BIGINT UNSIGNED NOT NULL,
    file_path VARCHAR(1024) NOT NULL,
    file_name VARCHAR(255) NULL,
    file_hash_sha256 CHAR(64) NULL,
    detection_reason VARCHAR(255) NULL,
    is_new_file TINYINT(1) NOT NULL DEFAULT 1,
    detected_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_edr_files_report
        FOREIGN KEY (report_id) REFERENCES edr_reports(id)
        ON DELETE CASCADE,
    KEY idx_edr_files_report (report_id),
    KEY idx_edr_files_detected (detected_at)
) ENGINE=InnoDB;

-- Utilisateurs: sessions actives, utilisateurs connectes
CREATE TABLE IF NOT EXISTS edr_report_user_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id BIGINT UNSIGNED NOT NULL,
    username VARCHAR(190) NOT NULL,
    session_id VARCHAR(190) NULL,
    source_ip VARCHAR(45) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    login_time DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_edr_sessions_report
        FOREIGN KEY (report_id) REFERENCES edr_reports(id)
        ON DELETE CASCADE,
    KEY idx_edr_sessions_report (report_id),
    KEY idx_edr_sessions_user (username)
) ENGINE=InnoDB;

-- Systeme: OS, version kernel, uptime, hostname
CREATE TABLE IF NOT EXISTS edr_report_system (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id BIGINT UNSIGNED NOT NULL,
    os_name VARCHAR(120) NULL,
    kernel_version VARCHAR(120) NULL,
    uptime_seconds BIGINT UNSIGNED NULL,
    hostname VARCHAR(190) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_edr_system_report
        FOREIGN KEY (report_id) REFERENCES edr_reports(id)
        ON DELETE CASCADE,
    UNIQUE KEY uk_edr_system_report (report_id)
) ENGINE=InnoDB;

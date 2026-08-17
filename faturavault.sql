-- FaturaVault - Esquema da base de dados
-- Executado automaticamente pelo install.php na primeira instalação

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_path VARCHAR(255) NOT NULL,
    category VARCHAR(50),
    supplier_name VARCHAR(255),
    supplier_vat VARCHAR(20),
    buyer_vat VARCHAR(20),
    document_type VARCHAR(10),
    document_date DATE,
    document_number VARCHAR(100),
    atcud VARCHAR(100),
    base_exempt DECIMAL(10,2) DEFAULT 0,
    base_reduced DECIMAL(10,2) DEFAULT 0,
    vat_reduced DECIMAL(10,2) DEFAULT 0,
    base_intermediate DECIMAL(10,2) DEFAULT 0,
    vat_intermediate DECIMAL(10,2) DEFAULT 0,
    base_standard DECIMAL(10,2) DEFAULT 0,
    vat_standard DECIMAL(10,2) DEFAULT 0,
    total_vat DECIMAL(10,2) DEFAULT 0,
    total DECIMAL(10,2) DEFAULT 0,
    qr_data TEXT,
    extracted_text TEXT,
    user_id INT,
    upload_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(50) NOT NULL,
    setting_value TEXT NOT NULL DEFAULT '',
    user_id INT NOT NULL,
    PRIMARY KEY (setting_key, user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS partners (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    percentage DECIMAL(5,2) DEFAULT 0,
    active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS bank_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    movement_date DATE NOT NULL,
    description VARCHAR(255) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    type ENUM('entrada', 'saida') NOT NULL,
    invoice_id INT,
    status ENUM('pending', 'matched', 'unmatched', 'deleted') DEFAULT 'pending',
    reference VARCHAR(100),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS advances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    partner_id INT NOT NULL,
    bank_movement_id INT,
    amount DECIMAL(10,2) NOT NULL,
    advance_date DATE NOT NULL,
    description VARCHAR(255),
    status ENUM('active', 'settled') DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE CASCADE,
    FOREIGN KEY (bank_movement_id) REFERENCES bank_movements(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 002 CRM: contacts/leads/travelers, relationships, tags, preferences.
CREATE TABLE IF NOT EXISTS contacts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  contact_type ENUM('client','lead','traveler','supplier_contact') NOT NULL DEFAULT 'client',
  lead_status ENUM('new','contacted','qualified','quoted','won','lost') NULL,
  source VARCHAR(100) NULL,
  birthday DATE NULL,
  anniversary DATE NULL,
  passport_expiry DATE NULL,
  passport_country CHAR(2) NULL,
  notes TEXT NULL,
  portal_user_id INT UNSIGNED NULL,
  assigned_to INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  deleted_at DATETIME NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  KEY idx_contacts_name (last_name, first_name),
  KEY idx_contacts_email (email),
  KEY idx_contacts_phone (phone),
  KEY idx_contacts_assigned (assigned_to, deleted_at),
  KEY idx_contacts_type (contact_type, deleted_at),
  KEY idx_contacts_birthday (birthday),
  KEY idx_contacts_passport (passport_expiry),
  CONSTRAINT fk_contacts_assigned FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_contacts_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_contacts_portal FOREIGN KEY (portal_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS contact_relationships (
  contact_id INT UNSIGNED NOT NULL,
  related_contact_id INT UNSIGNED NOT NULL,
  relationship VARCHAR(40) NOT NULL,
  PRIMARY KEY (contact_id, related_contact_id),
  CONSTRAINT fk_rel_a FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
  CONSTRAINT fk_rel_b FOREIGN KEY (related_contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS tags (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS contact_tags (
  contact_id INT UNSIGNED NOT NULL,
  tag_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (contact_id, tag_id),
  CONSTRAINT fk_ct_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
  CONSTRAINT fk_ct_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS contact_preferences (
  contact_id INT UNSIGNED PRIMARY KEY,
  seat VARCHAR(60) NULL, diet VARCHAR(120) NULL, hotel_style VARCHAR(120) NULL, interests VARCHAR(255) NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_pref_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS loyalty_accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contact_id INT UNSIGNED NOT NULL,
  program VARCHAR(100) NOT NULL,
  number_last4 CHAR(4) NULL,
  CONSTRAINT fk_loy_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

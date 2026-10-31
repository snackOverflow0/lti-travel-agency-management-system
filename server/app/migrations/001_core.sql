-- 001 Core: roles, permissions, staff/traveler accounts, sessions support, audit. MySQL 5.7+/MariaDB 10.3+, InnoDB, utf8mb4.
CREATE TABLE IF NOT EXISTS roles (
  id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(32) NOT NULL UNIQUE,
  name VARCHAR(80) NOT NULL,
  is_staff TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS permissions (
  id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(64) NOT NULL UNIQUE,
  description VARCHAR(190) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS role_permissions (
  role_id SMALLINT UNSIGNED NOT NULL,
  permission_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  full_name VARCHAR(160) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role_id SMALLINT UNSIGNED NOT NULL,
  phone VARCHAR(40) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_users_role (role_id),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  success TINYINT(1) NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_la_email (email, created_at),
  KEY idx_la_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS password_resets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_pr_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(64) NOT NULL,
  entity_type VARCHAR(40) NOT NULL DEFAULT '',
  entity_id VARCHAR(40) NULL,
  detail TEXT NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL,
  KEY idx_audit_entity (entity_type, entity_id),
  KEY idx_audit_user (user_id, created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO roles (code, name, is_staff) VALUES
('owner','Company Owner',1),('admin','Agency Administrator',1),('supervisor','Team Supervisor',1),('advisor','Travel Advisor',1),
('sales','Sales Staff',1),('operations','Operations Staff',1),('finance','Finance Staff',1),('traveler','Traveler',0);
INSERT IGNORE INTO permissions (code, description) VALUES
('contacts.view','View contacts'),('contacts.view_all','View all contacts (not only assigned)'),('contacts.create','Create contacts'),('contacts.edit','Edit contacts'),
('contacts.delete','Delete contacts'),('contacts.assign','Assign contacts to other staff'),('contacts.import','Import contacts'),('contacts.export','Export contacts'),
('staff.view','View staff directory'),('staff.manage','Manage staff accounts and recovery'),('trips.view','View trips'),('trips.view_all','View all trips'),
('trips.edit','Create and edit trips'),('trips.delete','Delete or cancel trips'),('trips.publish','Publish trips to the portal'),('bookings.manage','Manage bookings'),
('suppliers.manage','Manage suppliers'),('finance.view','View financial records'),('finance.record','Record payments and invoices'),('finance.reconcile','Reconcile commissions'),
('finance.payout','Approve staff payouts'),('automations.manage','Manage automations and workflows'),('settings.manage','Manage agency settings'),('calls.use','Use calling features'),
('calls.recordings','Access call recordings'),('reports.view','View reports'),('audit.view','View audit log'),('portal.access','Use the traveler portal');
INSERT IGNORE INTO role_permissions SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.code='owner' AND p.code <> 'portal.access';
INSERT IGNORE INTO role_permissions SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.code='admin' AND p.code NOT IN ('portal.access','finance.payout');
INSERT IGNORE INTO role_permissions SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.code='supervisor' AND p.code IN ('contacts.view','contacts.view_all','contacts.create','contacts.edit','contacts.delete','contacts.assign','contacts.import','contacts.export','staff.view','trips.view','trips.view_all','trips.edit','trips.delete','trips.publish','bookings.manage','finance.view','automations.manage','calls.use','calls.recordings','reports.view','audit.view');
INSERT IGNORE INTO role_permissions SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.code='advisor' AND p.code IN ('contacts.view','contacts.create','contacts.edit','contacts.export','trips.view','trips.edit','trips.publish','bookings.manage','finance.view','calls.use','reports.view');
INSERT IGNORE INTO role_permissions SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.code='sales' AND p.code IN ('contacts.view','contacts.view_all','contacts.create','contacts.edit','contacts.import','contacts.export','trips.view','trips.edit','calls.use','reports.view');
INSERT IGNORE INTO role_permissions SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.code='operations' AND p.code IN ('contacts.view','contacts.view_all','contacts.edit','trips.view','trips.view_all','trips.edit','bookings.manage','suppliers.manage','finance.record','calls.use');
INSERT IGNORE INTO role_permissions SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.code='finance' AND p.code IN ('contacts.view','contacts.view_all','trips.view','trips.view_all','suppliers.manage','finance.view','finance.record','finance.reconcile','finance.payout','reports.view');
INSERT IGNORE INTO role_permissions SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.code='traveler' AND p.code IN ('portal.access');

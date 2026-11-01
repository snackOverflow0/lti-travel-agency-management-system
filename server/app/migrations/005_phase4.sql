-- 005 Phase 4: groups, registrations, sub-trips, suppliers, bookings, forms, documents, content library. Additive only.
INSERT IGNORE INTO permissions (code, description) VALUES
('groups.manage','Manage group trips, registrations and sub-trips'),('groups.override_capacity','Authorize registrations beyond capacity or after the deadline'),
('forms.manage','Build forms and view submissions'),('documents.view','View and download documents'),('documents.manage','Upload, rename and archive documents'),
('library.view','Use the content library'),('library.manage','Create and edit content library items');
INSERT IGNORE INTO role_permissions SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.code IN ('owner','admin') AND p.code IN ('groups.manage','groups.override_capacity','forms.manage','documents.view','documents.manage','library.view','library.manage');
INSERT IGNORE INTO role_permissions SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.code IN ('supervisor','advisor') AND p.code IN ('groups.manage','forms.manage','documents.view','documents.manage','library.view','library.manage');
INSERT IGNORE INTO role_permissions SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.code='operations' AND p.code IN ('groups.manage','documents.view','documents.manage','library.view','library.manage');
INSERT IGNORE INTO role_permissions SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.code='sales' AND p.code IN ('forms.manage','documents.view','library.view');
INSERT IGNORE INTO role_permissions SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.code='finance' AND p.code IN ('documents.view','library.view');
ALTER TABLE trips
  ADD COLUMN coordinator_id INT UNSIGNED NULL,
  ADD COLUMN group_capacity SMALLINT UNSIGNED NULL,
  ADD COLUMN registration_deadline DATE NULL,
  ADD COLUMN group_details_json MEDIUMTEXT NULL,
  ADD KEY idx_trips_type (trip_type, parent_trip_id),
  ADD CONSTRAINT fk_trips_coord FOREIGN KEY (coordinator_id) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE itinerary_days ADD COLUMN origin_day_id INT UNSIGNED NULL, ADD KEY idx_days_origin (origin_day_id);
ALTER TABLE itinerary_activities ADD COLUMN origin_activity_id INT UNSIGNED NULL, ADD COLUMN is_customized TINYINT(1) NOT NULL DEFAULT 0, ADD KEY idx_acts_origin (origin_activity_id);
ALTER TABLE group_registrations
  ADD COLUMN contact_id INT UNSIGNED NULL,
  ADD COLUMN status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
  ADD COLUMN room_preference VARCHAR(190) NULL,
  ADD COLUMN special_requests TEXT NULL,
  ADD COLUMN over_capacity_by INT UNSIGNED NULL,
  ADD COLUMN created_by INT UNSIGNED NULL,
  ADD COLUMN cancelled_at DATETIME NULL,
  ADD COLUMN cancel_reason VARCHAR(255) NULL,
  ADD KEY idx_greg_status (group_trip_id, status),
  ADD CONSTRAINT fk_greg_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_greg_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_greg_override FOREIGN KEY (over_capacity_by) REFERENCES users(id) ON DELETE SET NULL;
CREATE TABLE IF NOT EXISTS group_registration_travelers (
  registration_id INT UNSIGNED NOT NULL, contact_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (registration_id, contact_id), KEY idx_grt_contact (contact_id),
  CONSTRAINT fk_grt_reg FOREIGN KEY (registration_id) REFERENCES group_registrations(id) ON DELETE CASCADE,
  CONSTRAINT fk_grt_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE suppliers
  ADD COLUMN status ENUM('active','archived') NOT NULL DEFAULT 'active',
  ADD COLUMN destination VARCHAR(190) NULL, ADD COLUMN website VARCHAR(255) NULL, ADD COLUMN address VARCHAR(255) NULL,
  ADD COLUMN contract_reference VARCHAR(100) NULL, ADD COLUMN contract_notes TEXT NULL,
  ADD COLUMN archived_at DATETIME NULL, ADD COLUMN created_by INT UNSIGNED NULL, ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1,
  ADD KEY idx_sup_status (status, category),
  ADD CONSTRAINT fk_sup_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;
CREATE TABLE IF NOT EXISTS supplier_contacts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, supplier_id INT UNSIGNED NOT NULL, name VARCHAR(160) NOT NULL, role VARCHAR(100) NULL,
  email VARCHAR(190) NULL, phone VARCHAR(40) NULL, is_primary TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL,
  KEY idx_sc_supplier (supplier_id), CONSTRAINT fk_sc_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE bookings
  ADD COLUMN end_date DATE NULL, ADD COLUMN notes TEXT NULL, ADD COLUMN confirmation_details TEXT NULL,
  ADD COLUMN cancelled_at DATETIME NULL, ADD COLUMN cancel_reason VARCHAR(255) NULL,
  ADD COLUMN created_by INT UNSIGNED NULL, ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1,
  ADD KEY idx_bk_status (status, booking_type),
  ADD CONSTRAINT fk_bk_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;
CREATE TABLE IF NOT EXISTS booking_travelers (
  booking_id INT UNSIGNED NOT NULL, contact_id INT UNSIGNED NOT NULL, PRIMARY KEY (booking_id, contact_id), KEY idx_bt_contact (contact_id),
  CONSTRAINT fk_bt_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_bt_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE forms
  MODIFY definition_json MEDIUMTEXT NULL,
  ADD COLUMN status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft', ADD COLUMN category VARCHAR(60) NULL,
  ADD COLUMN steps_json TEXT NULL, ADD COLUMN settings_json TEXT NULL, ADD COLUMN published_at DATETIME NULL,
  ADD COLUMN created_by INT UNSIGNED NULL, ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1,
  ADD CONSTRAINT fk_forms_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;
CREATE TABLE IF NOT EXISTS form_fields (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, form_id INT UNSIGNED NOT NULL, field_key VARCHAR(40) NOT NULL, label VARCHAR(190) NOT NULL,
  field_type ENUM('text','textarea','email','phone','date','number','select','radio','checkbox','file') NOT NULL, is_required TINYINT(1) NOT NULL DEFAULT 0,
  options_json TEXT NULL, show_if VARCHAR(190) NULL, step TINYINT UNSIGNED NOT NULL DEFAULT 1, sort_order SMALLINT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_ff_key (form_id, field_key), CONSTRAINT fk_ff_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE form_submissions
  ADD COLUMN submitted_by INT UNSIGNED NULL, ADD COLUMN ip VARCHAR(45) NULL, ADD COLUMN source ENUM('staff','public','portal') NOT NULL DEFAULT 'public',
  ADD KEY idx_fs_form (form_id, created_at), ADD KEY idx_fs_ip (ip, created_at),
  ADD CONSTRAINT fk_fs_user FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL;
CREATE TABLE IF NOT EXISTS form_answers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, submission_id INT UNSIGNED NOT NULL, field_id INT UNSIGNED NULL, field_key VARCHAR(40) NOT NULL, value TEXT NULL,
  KEY idx_fa_sub (submission_id), CONSTRAINT fk_fa_sub FOREIGN KEY (submission_id) REFERENCES form_submissions(id) ON DELETE CASCADE,
  CONSTRAINT fk_fa_field FOREIGN KEY (field_id) REFERENCES form_fields(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE documents
  MODIFY stored_name VARCHAR(100) NOT NULL,
  ADD COLUMN supplier_id INT UNSIGNED NULL, ADD COLUMN title VARCHAR(255) NULL, ADD COLUMN sha256 CHAR(64) NULL, ADD COLUMN updated_at DATETIME NULL,
  ADD KEY idx_doc_trip (trip_id), ADD KEY idx_doc_contact (contact_id), ADD KEY idx_doc_supplier (supplier_id), ADD KEY idx_doc_deleted (deleted_at),
  ADD CONSTRAINT fk_doc_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_doc_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL;
CREATE TABLE IF NOT EXISTS content_folders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, parent_id INT UNSIGNED NULL, created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, KEY idx_cf_parent (parent_id),
  CONSTRAINT fk_cf_parent FOREIGN KEY (parent_id) REFERENCES content_folders(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cf_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS content_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, folder_id INT UNSIGNED NULL,
  kind ENUM('itinerary','activity','checklist','supplier_info','document','guide') NOT NULL, title VARCHAR(190) NOT NULL, summary VARCHAR(500) NULL,
  body_json MEDIUMTEXT NOT NULL, tags_json TEXT NULL, status ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_by INT UNSIGNED NULL, updated_by INT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, version INT UNSIGNED NOT NULL DEFAULT 1,
  KEY idx_ct_kind (kind, status), KEY idx_ct_folder (folder_id),
  CONSTRAINT fk_ct_folder FOREIGN KEY (folder_id) REFERENCES content_folders(id) ON DELETE SET NULL,
  CONSTRAINT fk_ct_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ct_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS content_template_usage (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, template_id INT UNSIGNED NOT NULL, trip_id INT UNSIGNED NULL, activity_id INT UNSIGNED NULL, used_by INT UNSIGNED NULL, created_at DATETIME NOT NULL,
  KEY idx_ctu_tpl (template_id), KEY idx_ctu_trip (trip_id),
  CONSTRAINT fk_ctu_tpl FOREIGN KEY (template_id) REFERENCES content_templates(id) ON DELETE CASCADE,
  CONSTRAINT fk_ctu_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_ctu_act FOREIGN KEY (activity_id) REFERENCES itinerary_activities(id) ON DELETE SET NULL,
  CONSTRAINT fk_ctu_user FOREIGN KEY (used_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

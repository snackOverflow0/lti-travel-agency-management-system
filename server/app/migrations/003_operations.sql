-- 003 Operations schema for later phases (trips, itineraries, groups, suppliers, bookings, finance, tasks, automations,
-- email, forms, documents, appointments, calls, notifications). Money is DECIMAL(12,2). API endpoints arrive phase by phase.
CREATE TABLE IF NOT EXISTS trips (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'inquiry',
  trip_type ENUM('standard','group','sub') NOT NULL DEFAULT 'standard',
  parent_trip_id INT UNSIGNED NULL,
  primary_contact_id INT UNSIGNED NULL,
  destination VARCHAR(190) NULL,
  start_date DATE NULL, end_date DATE NULL,
  owner_id INT UNSIGNED NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 0,
  is_customized TINYINT(1) NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  KEY idx_trips_status (status, deleted_at), KEY idx_trips_dates (start_date, end_date), KEY idx_trips_owner (owner_id),
  CONSTRAINT fk_trips_parent FOREIGN KEY (parent_trip_id) REFERENCES trips(id) ON DELETE SET NULL,
  CONSTRAINT fk_trips_contact FOREIGN KEY (primary_contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
  CONSTRAINT fk_trips_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS trip_travelers (
  trip_id INT UNSIGNED NOT NULL, contact_id INT UNSIGNED NOT NULL,
  permission ENUM('primary','full','limited') NOT NULL DEFAULT 'full',
  PRIMARY KEY (trip_id, contact_id),
  CONSTRAINT fk_tt_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_tt_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS trip_collaborators (
  trip_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, PRIMARY KEY (trip_id, user_id),
  CONSTRAINT fk_tc_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_tc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS itinerary_options (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, trip_id INT UNSIGNED NOT NULL, name VARCHAR(120) NOT NULL, sort_order SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT fk_io_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS itinerary_days (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, option_id INT UNSIGNED NOT NULL, title VARCHAR(190) NOT NULL, sort_order SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT fk_id_option FOREIGN KEY (option_id) REFERENCES itinerary_options(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS suppliers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL UNIQUE, category VARCHAR(40) NOT NULL,
  is_preferred TINYINT(1) NOT NULL DEFAULT 0, commission_rate DECIMAL(5,2) NULL, email VARCHAR(190) NULL, phone VARCHAR(40) NULL, notes TEXT NULL,
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS bookings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(30) NOT NULL UNIQUE, trip_id INT UNSIGNED NULL, supplier_id INT UNSIGNED NULL,
  booking_type VARCHAR(30) NOT NULL, status ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending',
  confirmation_number VARCHAR(60) NULL, travel_date DATE NULL, amount DECIMAL(12,2) NOT NULL DEFAULT 0, supplier_cost DECIMAL(12,2) NULL,
  commission_rate DECIMAL(5,2) NULL, commission_expected DECIMAL(12,2) NOT NULL DEFAULT 0, agent_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
  KEY idx_bk_trip (trip_id), KEY idx_bk_supplier (supplier_id), KEY idx_bk_date (travel_date),
  CONSTRAINT fk_bk_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE SET NULL,
  CONSTRAINT fk_bk_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
  CONSTRAINT fk_bk_agent FOREIGN KEY (agent_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS itinerary_activities (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, day_id INT UNSIGNED NOT NULL, activity_type VARCHAR(20) NOT NULL, title VARCHAR(190) NOT NULL,
  description TEXT NULL, start_time TIME NULL, location VARCHAR(190) NULL, price DECIMAL(12,2) NULL, supplier_id INT UNSIGNED NULL,
  booking_id INT UNSIGNED NULL, confirmation_number VARCHAR(60) NULL, internal_note TEXT NULL, client_note TEXT NULL, details_json TEXT NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  KEY idx_ia_day (day_id, sort_order),
  CONSTRAINT fk_ia_day FOREIGN KEY (day_id) REFERENCES itinerary_days(id) ON DELETE CASCADE,
  CONSTRAINT fk_ia_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
  CONSTRAINT fk_ia_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS itinerary_versions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, trip_id INT UNSIGNED NOT NULL, label VARCHAR(120) NOT NULL, snapshot_json MEDIUMTEXT NOT NULL,
  created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL,
  CONSTRAINT fk_iv_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_iv_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS trip_approvals (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, trip_id INT UNSIGNED NOT NULL, contact_id INT UNSIGNED NULL, option_id INT UNSIGNED NULL,
  action ENUM('approved','changes_requested','comment') NOT NULL, message TEXT NULL, created_at DATETIME NOT NULL,
  CONSTRAINT fk_ta_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_ta_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS group_rooms (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, trip_id INT UNSIGNED NOT NULL, name VARCHAR(120) NOT NULL, price_per_person DECIMAL(12,2) NOT NULL, capacity SMALLINT NOT NULL,
  CONSTRAINT fk_gr_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS group_registrations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, group_trip_id INT UNSIGNED NOT NULL, sub_trip_id INT UNSIGNED NOT NULL, room_id INT UNSIGNED NULL, created_at DATETIME NOT NULL,
  CONSTRAINT fk_greg_group FOREIGN KEY (group_trip_id) REFERENCES trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_greg_sub FOREIGN KEY (sub_trip_id) REFERENCES trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_greg_room FOREIGN KEY (room_id) REFERENCES group_rooms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS quotations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, number VARCHAR(30) NOT NULL UNIQUE, trip_id INT UNSIGNED NULL, contact_id INT UNSIGNED NULL,
  status ENUM('draft','sent','accepted','declined','expired') NOT NULL DEFAULT 'draft', valid_until DATE NULL, total DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
  CONSTRAINT fk_q_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE SET NULL,
  CONSTRAINT fk_q_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS invoices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, number VARCHAR(30) NOT NULL UNIQUE, trip_id INT UNSIGNED NULL, contact_id INT UNSIGNED NULL,
  status ENUM('draft','sent','paid','void') NOT NULL DEFAULT 'draft', issue_date DATE NOT NULL, due_date DATE NULL, total DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
  CONSTRAINT fk_inv_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE SET NULL,
  CONSTRAINT fk_inv_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS document_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, quotation_id INT UNSIGNED NULL, invoice_id INT UNSIGNED NULL, description VARCHAR(255) NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1, unit_amount DECIMAL(12,2) NOT NULL, booking_id INT UNSIGNED NULL,
  CONSTRAINT fk_dl_q FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE CASCADE,
  CONSTRAINT fk_dl_inv FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_dl_bk FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS payment_schedules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, booking_id INT UNSIGNED NULL, trip_id INT UNSIGNED NULL, label VARCHAR(80) NOT NULL,
  amount DECIMAL(12,2) NOT NULL, due_date DATE NOT NULL, status ENUM('scheduled','partially_paid','paid','void') NOT NULL DEFAULT 'scheduled',
  KEY idx_ps_due (due_date, status),
  CONSTRAINT fk_ps_bk FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_ps_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, schedule_id INT UNSIGNED NULL, invoice_id INT UNSIGNED NULL, amount DECIMAL(12,2) NOT NULL,
  method ENUM('bank_transfer','cash','check','card_external','other') NOT NULL, reference VARCHAR(100) NULL, received_on DATE NOT NULL,
  verified_by INT UNSIGNED NULL, verified_at DATETIME NULL, is_refund TINYINT(1) NOT NULL DEFAULT 0, notes VARCHAR(255) NULL,
  recorded_by INT UNSIGNED NULL, created_at DATETIME NOT NULL,
  CONSTRAINT fk_pay_sched FOREIGN KEY (schedule_id) REFERENCES payment_schedules(id) ON DELETE SET NULL,
  CONSTRAINT fk_pay_inv FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
  CONSTRAINT fk_pay_verifier FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS commissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, booking_id INT UNSIGNED NOT NULL UNIQUE, expected DECIMAL(12,2) NOT NULL, received DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('expected','matched','partially_received','paid_out','cancelled') NOT NULL DEFAULT 'expected', payout_id INT UNSIGNED NULL,
  CONSTRAINT fk_com_bk FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS tasks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, description TEXT NULL, due_date DATE NULL,
  priority ENUM('high','medium','low') NOT NULL DEFAULT 'medium', assigned_to INT UNSIGNED NULL, trip_id INT UNSIGNED NULL, contact_id INT UNSIGNED NULL,
  source VARCHAR(120) NULL, dedupe_key VARCHAR(190) NULL UNIQUE, completed_at DATETIME NULL, created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL,
  KEY idx_tasks_due (assigned_to, completed_at, due_date),
  CONSTRAINT fk_task_user FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_task_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_task_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS automations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL, scope ENUM('trip','contact','call') NOT NULL, trigger_code VARCHAR(40) NOT NULL,
  timing_json TEXT NOT NULL, conditions_json TEXT NOT NULL, actions_json TEXT NOT NULL, status ENUM('active','paused') NOT NULL DEFAULT 'paused',
  attach_type VARCHAR(10) NULL, attach_id INT UNSIGNED NULL, workflow_id INT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
  KEY idx_auto_trigger (trigger_code, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS automation_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, automation_id INT UNSIGNED NOT NULL, record_type VARCHAR(10) NOT NULL, record_id INT UNSIGNED NOT NULL,
  run_key VARCHAR(190) NULL UNIQUE, result ENUM('success','skipped','failed') NOT NULL, reason VARCHAR(255) NULL, actions_json TEXT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 1, created_at DATETIME NOT NULL,
  KEY idx_ar_auto (automation_id, created_at),
  CONSTRAINT fk_ar_auto FOREIGN KEY (automation_id) REFERENCES automations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS scheduled_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, job_type VARCHAR(40) NOT NULL, payload_json TEXT NOT NULL, run_at DATETIME NOT NULL,
  status ENUM('pending','running','done','failed') NOT NULL DEFAULT 'pending', attempts TINYINT UNSIGNED NOT NULL DEFAULT 0, last_error VARCHAR(255) NULL,
  dedupe_key VARCHAR(190) NULL UNIQUE, created_at DATETIME NOT NULL, KEY idx_jobs_due (status, run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS workflows (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL, scope ENUM('trip','contact') NOT NULL, definition_json MEDIUMTEXT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS email_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL, category VARCHAR(60) NULL, subject VARCHAR(255) NOT NULL, body MEDIUMTEXT NOT NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS emails (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, direction ENUM('outbound','inbound') NOT NULL, status ENUM('draft','scheduled','queued','sent','failed','received') NOT NULL,
  to_addr VARCHAR(500) NULL, from_addr VARCHAR(190) NULL, subject VARCHAR(255) NULL, body MEDIUMTEXT NULL, contact_id INT UNSIGNED NULL, trip_id INT UNSIGNED NULL,
  send_at DATETIME NULL, sent_at DATETIME NULL, error VARCHAR(255) NULL, created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL,
  KEY idx_email_status (status, send_at),
  CONSTRAINT fk_em_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
  CONSTRAINT fk_em_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS forms (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL, definition_json MEDIUMTEXT NOT NULL, public_token CHAR(32) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS form_submissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, form_id INT UNSIGNED NOT NULL, data_json MEDIUMTEXT NOT NULL, contact_id INT UNSIGNED NULL, trip_id INT UNSIGNED NULL,
  status ENUM('new','reviewed') NOT NULL DEFAULT 'new', created_at DATETIME NOT NULL,
  CONSTRAINT fk_fs_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE,
  CONSTRAINT fk_fs_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
  CONSTRAINT fk_fs_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS documents (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, original_name VARCHAR(255) NOT NULL, stored_name CHAR(64) NOT NULL UNIQUE, mime_type VARCHAR(100) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL, category VARCHAR(60) NOT NULL, visibility ENUM('internal','client') NOT NULL DEFAULT 'internal',
  trip_id INT UNSIGNED NULL, contact_id INT UNSIGNED NULL, booking_id INT UNSIGNED NULL, uploaded_by INT UNSIGNED NULL, created_at DATETIME NOT NULL, deleted_at DATETIME NULL,
  CONSTRAINT fk_doc_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE SET NULL,
  CONSTRAINT fk_doc_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
  CONSTRAINT fk_doc_bk FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS appointments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(190) NOT NULL, starts_at DATETIME NOT NULL, ends_at DATETIME NOT NULL,
  contact_id INT UNSIGNED NULL, staff_id INT UNSIGNED NULL, location VARCHAR(190) NULL, notes TEXT NULL, created_at DATETIME NOT NULL,
  KEY idx_appt_staff (staff_id, starts_at),
  CONSTRAINT fk_appt_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
  CONSTRAINT fk_appt_staff FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS calls (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, mode ENUM('internet','phone_handoff') NOT NULL, direction ENUM('inbound','outbound') NOT NULL,
  status ENUM('answered','missed','declined','busy','failed','voicemail','logged') NOT NULL, phone VARCHAR(40) NULL,
  contact_id INT UNSIGNED NULL, trip_id INT UNSIGNED NULL, staff_id INT UNSIGNED NULL, started_at DATETIME NOT NULL, duration_seconds INT UNSIGNED NULL,
  duration_source ENUM('measured','entered','unknown') NOT NULL DEFAULT 'unknown', disposition VARCHAR(40) NULL, notes TEXT NULL,
  followup_date DATE NULL, created_at DATETIME NOT NULL,
  KEY idx_calls_contact (contact_id, started_at), KEY idx_calls_staff (staff_id, started_at),
  CONSTRAINT fk_call_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
  CONSTRAINT fk_call_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE SET NULL,
  CONSTRAINT fk_call_staff FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS call_signals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, call_id INT UNSIGNED NOT NULL, from_user INT UNSIGNED NOT NULL, to_user INT UNSIGNED NOT NULL,
  kind ENUM('offer','answer','ice','hangup','reject') NOT NULL, payload MEDIUMTEXT NOT NULL, consumed_at DATETIME NULL, created_at DATETIME NOT NULL,
  KEY idx_sig_inbox (to_user, consumed_at, id),
  CONSTRAINT fk_sig_call FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS agent_presence (
  user_id INT UNSIGNED PRIMARY KEY, status ENUM('available','busy','away','offline') NOT NULL DEFAULT 'offline', last_seen_at DATETIME NOT NULL,
  CONSTRAINT fk_presence_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS voicemails (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, call_id INT UNSIGNED NULL, contact_id INT UNSIGNED NULL, staff_id INT UNSIGNED NULL, note TEXT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0, archived_at DATETIME NULL, created_at DATETIME NOT NULL,
  CONSTRAINT fk_vm_call FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, message VARCHAR(255) NOT NULL, ref_type VARCHAR(20) NULL, ref_id INT UNSIGNED NULL,
  read_at DATETIME NULL, created_at DATETIME NOT NULL, KEY idx_notif_user (user_id, read_at, id),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

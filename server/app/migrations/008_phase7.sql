-- 008 Phase 7: call management (manual logging and phone-app handoff), dispositions, notes, call history events,
-- contact phone numbers and callbacks. Additive; the existing `calls` table is extended and legacy values mapped.
INSERT IGNORE INTO permissions (code, description) VALUES ('calls.view_all','See all staff call records'),('calls.configure','Configure call dispositions and calling settings');
INSERT IGNORE INTO role_permissions SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.code IN ('owner','admin','supervisor') AND p.code='calls.view_all';
INSERT IGNORE INTO role_permissions SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.code IN ('owner','admin') AND p.code='calls.configure';
INSERT IGNORE INTO app_settings (setting_key, setting_value, updated_at) VALUES ('default_phone_country','US',UTC_TIMESTAMP());
CREATE TABLE IF NOT EXISTS call_dispositions (
  id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL UNIQUE, label VARCHAR(80) NOT NULL, requires_followup TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1, sort_order SMALLINT NOT NULL DEFAULT 0, updated_by INT UNSIGNED NULL, updated_at DATETIME NOT NULL,
  CONSTRAINT fk_cdisp_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO call_dispositions (code, label, requires_followup, sort_order, updated_at) VALUES
('connected','Connected',0,1,UTC_TIMESTAMP()),('no_answer','No answer',1,2,UTC_TIMESTAMP()),('busy','Busy',1,3,UTC_TIMESTAMP()),('voicemail_left','Voicemail left',1,4,UTC_TIMESTAMP()),
('callback_requested','Callback requested',1,5,UTC_TIMESTAMP()),('wrong_number','Wrong number',0,6,UTC_TIMESTAMP()),('interested','Interested',1,7,UTC_TIMESTAMP()),('not_interested','Not interested',0,8,UTC_TIMESTAMP()),
('booking_inquiry','Booking inquiry',1,9,UTC_TIMESTAMP()),('follow_up_required','Follow-up required',1,10,UTC_TIMESTAMP()),('resolved','Resolved',0,11,UTC_TIMESTAMP());
ALTER TABLE calls MODIFY status ENUM('answered','missed','declined','busy','failed','voicemail','logged','attempted','connected','no_answer','voicemail_left','cancelled') NOT NULL DEFAULT 'attempted';
UPDATE calls SET status = CASE status WHEN 'answered' THEN 'connected' WHEN 'declined' THEN 'no_answer' WHEN 'voicemail' THEN 'voicemail_left' WHEN 'logged' THEN 'connected' ELSE status END;
ALTER TABLE calls
  MODIFY status ENUM('attempted','connected','no_answer','busy','voicemail_left','missed','failed','cancelled') NOT NULL DEFAULT 'attempted',
  ADD COLUMN source ENUM('manual_log','external_handoff','webrtc_verified','provider_verified') NOT NULL DEFAULT 'manual_log' AFTER mode,
  ADD COLUMN verification ENUM('user_entered','handoff_unconfirmed','verified') NOT NULL DEFAULT 'user_entered' AFTER source,
  ADD COLUMN phone_e164 VARCHAR(16) NULL AFTER phone, ADD COLUMN booking_id INT UNSIGNED NULL, ADD COLUMN ended_at DATETIME NULL,
  ADD COLUMN disposition_id SMALLINT UNSIGNED NULL, ADD COLUMN created_by INT UNSIGNED NULL, ADD COLUMN updated_at DATETIME NULL, ADD COLUMN archived_at DATETIME NULL,
  ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN provider_event_id VARCHAR(120) NULL UNIQUE,
  ADD KEY idx_calls_e164 (phone_e164), ADD KEY idx_calls_status (status, started_at), ADD KEY idx_calls_dir (direction, started_at),
  ADD CONSTRAINT fk_call_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_call_disp FOREIGN KEY (disposition_id) REFERENCES call_dispositions(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_call_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;
CREATE TABLE IF NOT EXISTS call_notes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, call_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NULL, body TEXT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NULL,
  KEY idx_cn_call (call_id, id), CONSTRAINT fk_cn_call FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE, CONSTRAINT fk_cn_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS call_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, call_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NULL, event VARCHAR(40) NOT NULL, before_json TEXT NULL, after_json TEXT NULL, created_at DATETIME NOT NULL,
  KEY idx_ce_call (call_id, id), CONSTRAINT fk_ce_call FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE, CONSTRAINT fk_ce_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS contact_phones (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, contact_id INT UNSIGNED NOT NULL, label ENUM('mobile','home','work','other') NOT NULL DEFAULT 'mobile', phone_raw VARCHAR(40) NOT NULL,
  phone_e164 VARCHAR(16) NULL, is_preferred TINYINT(1) NOT NULL DEFAULT 0, source ENUM('contact_field','added') NOT NULL DEFAULT 'added', created_at DATETIME NOT NULL,
  UNIQUE KEY uq_cp (contact_id, phone_raw), KEY idx_cp_e164 (phone_e164), CONSTRAINT fk_cp_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS callbacks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, call_id INT UNSIGNED NULL, contact_id INT UNSIGNED NOT NULL, trip_id INT UNSIGNED NULL, assigned_to INT UNSIGNED NOT NULL,
  scheduled_at DATETIME NOT NULL, timezone VARCHAR(64) NOT NULL, status ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending', notes VARCHAR(500) NULL,
  task_id INT UNSIGNED NULL, reschedule_count TINYINT UNSIGNED NOT NULL DEFAULT 0, completed_at DATETIME NULL, created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NULL,
  KEY idx_cb_due (status, scheduled_at), KEY idx_cb_user (assigned_to, status), KEY idx_cb_contact (contact_id),
  CONSTRAINT fk_cb_call FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE SET NULL, CONSTRAINT fk_cb_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
  CONSTRAINT fk_cb_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE SET NULL, CONSTRAINT fk_cb_user FOREIGN KEY (assigned_to) REFERENCES users(id),
  CONSTRAINT fk_cb_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL, CONSTRAINT fk_cb_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

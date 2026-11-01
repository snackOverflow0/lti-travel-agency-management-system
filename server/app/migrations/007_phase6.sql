-- 007 Phase 6: tasks, email templates and messages, workflows, executions, domain events, job queue, follow-ups,
-- notifications and activity timeline. Additive; existing rows preserved (legacy enum values mapped before narrowing).
INSERT IGNORE INTO permissions (code, description) VALUES
('tasks.manage','Create and work on tasks'),('tasks.view_all','See all staff tasks'),('email.send','Compose and queue client email'),
('email.templates','Manage email templates'),('workflows.manage','Create and edit workflows and follow-ups'),('jobs.view','Monitor background jobs and the scheduler');
INSERT IGNORE INTO role_permissions SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.is_staff=1 AND p.code='tasks.manage';
INSERT IGNORE INTO role_permissions SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.code IN ('owner','admin','supervisor','operations') AND p.code='tasks.view_all';
INSERT IGNORE INTO role_permissions SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.code IN ('owner','admin','supervisor','advisor','sales','operations') AND p.code='email.send';
INSERT IGNORE INTO role_permissions SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.code IN ('owner','admin','supervisor') AND p.code IN ('email.templates','workflows.manage');
INSERT IGNORE INTO role_permissions SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.code IN ('owner','admin') AND p.code='jobs.view';
INSERT IGNORE INTO app_settings (setting_key, setting_value, updated_at) VALUES ('agency_timezone','America/New_York',UTC_TIMESTAMP()),('agency_name','Luxury Travel Institute',UTC_TIMESTAMP());
-- tasks (existing)
ALTER TABLE tasks MODIFY priority ENUM('high','medium','low','normal','urgent') NOT NULL DEFAULT 'medium';
UPDATE tasks SET priority='normal' WHERE priority='medium';
ALTER TABLE tasks
  MODIFY priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  ADD COLUMN status ENUM('todo','in_progress','blocked','completed','archived') NOT NULL DEFAULT 'todo' AFTER priority,
  ADD COLUMN booking_id INT UNSIGNED NULL, ADD COLUMN invoice_id INT UNSIGNED NULL, ADD COLUMN completed_by INT UNSIGNED NULL,
  ADD COLUMN archived_at DATETIME NULL, ADD COLUMN updated_at DATETIME NULL, ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1,
  ADD KEY idx_tasks_status (status, due_date), ADD KEY idx_tasks_creator (created_by),
  ADD CONSTRAINT fk_task_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_task_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_task_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_task_completer FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL;
UPDATE tasks SET status='completed' WHERE completed_at IS NOT NULL;
CREATE TABLE IF NOT EXISTS task_comments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, task_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NULL, body TEXT NOT NULL, created_at DATETIME NOT NULL,
  KEY idx_tc_task (task_id, id), CONSTRAINT fk_tcm_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE, CONSTRAINT fk_tcm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS task_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, task_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NULL, field VARCHAR(20) NOT NULL, old_value VARCHAR(190) NULL, new_value VARCHAR(190) NULL, created_at DATETIME NOT NULL,
  KEY idx_th_task (task_id, id), CONSTRAINT fk_th_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE, CONSTRAINT fk_th_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- email templates and messages (existing tables)
ALTER TABLE email_templates
  ADD COLUMN status ENUM('active','archived') NOT NULL DEFAULT 'active', ADD COLUMN created_by INT UNSIGNED NULL, ADD COLUMN updated_by INT UNSIGNED NULL,
  ADD COLUMN duplicated_from INT UNSIGNED NULL, ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1, ADD KEY idx_et_status (status, category),
  ADD CONSTRAINT fk_et_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL, ADD CONSTRAINT fk_et_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE emails MODIFY status ENUM('draft','scheduled','queued','sent','failed','received','sending','accepted','unknown','cancelled') NOT NULL DEFAULT 'draft';
UPDATE emails SET status='accepted' WHERE status='sent'; UPDATE emails SET status='queued' WHERE status='scheduled';
ALTER TABLE emails
  MODIFY status ENUM('draft','queued','sending','accepted','failed','unknown','cancelled') NOT NULL DEFAULT 'draft',
  ADD COLUMN booking_id INT UNSIGNED NULL, ADD COLUMN invoice_id INT UNSIGNED NULL, ADD COLUMN template_id INT UNSIGNED NULL, ADD COLUMN workflow_execution_id INT UNSIGNED NULL,
  ADD COLUMN attempts TINYINT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN accepted_at DATETIME NULL, ADD COLUMN transport_message_id VARCHAR(190) NULL,
  ADD COLUMN updated_at DATETIME NULL, ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1,
  ADD KEY idx_em_contact (contact_id, id), ADD KEY idx_em_trip (trip_id, id), ADD KEY idx_em_creator (created_by),
  ADD CONSTRAINT fk_em_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL, ADD CONSTRAINT fk_em_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_em_template FOREIGN KEY (template_id) REFERENCES email_templates(id) ON DELETE SET NULL, ADD CONSTRAINT fk_em_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;
CREATE TABLE IF NOT EXISTS email_send_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, email_id INT UNSIGNED NOT NULL, attempt_no TINYINT UNSIGNED NOT NULL, job_id BIGINT UNSIGNED NULL, transport VARCHAR(20) NOT NULL,
  started_at DATETIME NOT NULL, finished_at DATETIME NULL, result ENUM('accepted','failed','unknown') NULL, response VARCHAR(255) NULL,
  UNIQUE KEY uq_attempt (email_id, attempt_no), CONSTRAINT fk_esa_email FOREIGN KEY (email_id) REFERENCES emails(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- workflows (existing table) and executions
ALTER TABLE workflows
  MODIFY scope VARCHAR(20) NULL, MODIFY definition_json MEDIUMTEXT NULL,
  ADD COLUMN description VARCHAR(500) NULL, ADD COLUMN status ENUM('draft','active','disabled','archived') NOT NULL DEFAULT 'draft',
  ADD COLUMN trigger_type VARCHAR(40) NULL, ADD COLUMN trigger_config_json TEXT NULL, ADD COLUMN conditions_json TEXT NULL, ADD COLUMN actions_json MEDIUMTEXT NULL,
  ADD COLUMN delay_minutes INT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN created_by INT UNSIGNED NULL, ADD COLUMN updated_by INT UNSIGNED NULL,
  ADD COLUMN duplicated_from INT UNSIGNED NULL, ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1, ADD KEY idx_wf_trigger (status, trigger_type),
  ADD CONSTRAINT fk_wf_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL, ADD CONSTRAINT fk_wf_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL;
UPDATE workflows SET status = IF(is_active=1,'active','disabled');
CREATE TABLE IF NOT EXISTS domain_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, event_type VARCHAR(40) NOT NULL, entity_type VARCHAR(20) NOT NULL, entity_id INT UNSIGNED NOT NULL, actor_id INT UNSIGNED NULL,
  payload_json TEXT NULL, created_at DATETIME NOT NULL, processed_at DATETIME NULL, KEY idx_de_pending (processed_at, id), KEY idx_de_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS workflow_executions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, workflow_id INT UNSIGNED NOT NULL, workflow_version INT UNSIGNED NOT NULL, event_id BIGINT UNSIGNED NULL, trigger_type VARCHAR(40) NOT NULL,
  entity_type VARCHAR(20) NULL, entity_id INT UNSIGNED NULL, dedupe_key VARCHAR(190) NOT NULL UNIQUE, status ENUM('pending','running','completed','failed','skipped') NOT NULL DEFAULT 'pending',
  scheduled_for DATETIME NOT NULL, started_at DATETIME NULL, finished_at DATETIME NULL, attempts TINYINT UNSIGNED NOT NULL DEFAULT 0, result_json TEXT NULL, error VARCHAR(255) NULL, created_at DATETIME NOT NULL,
  KEY idx_wx_wf (workflow_id, id), KEY idx_wx_status (status, scheduled_for),
  CONSTRAINT fk_wx_wf FOREIGN KEY (workflow_id) REFERENCES workflows(id), CONSTRAINT fk_wx_event FOREIGN KEY (event_id) REFERENCES domain_events(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- job queue (existing table scheduled_jobs)
ALTER TABLE scheduled_jobs MODIFY status ENUM('pending','running','done','failed','completed','cancelled') NOT NULL DEFAULT 'pending';
UPDATE scheduled_jobs SET status='completed' WHERE status='done';
ALTER TABLE scheduled_jobs
  MODIFY status ENUM('pending','running','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
  ADD COLUMN max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3, ADD COLUMN locked_by VARCHAR(64) NULL, ADD COLUMN locked_until DATETIME NULL,
  ADD COLUMN started_at DATETIME NULL, ADD COLUMN finished_at DATETIME NULL, ADD COLUMN updated_at DATETIME NULL, ADD COLUMN needs_review TINYINT(1) NOT NULL DEFAULT 0,
  ADD KEY idx_jobs_lease (status, locked_until);
CREATE TABLE IF NOT EXISTS job_execution_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, job_id BIGINT UNSIGNED NOT NULL, attempt TINYINT UNSIGNED NOT NULL, worker VARCHAR(64) NULL, started_at DATETIME NULL, finished_at DATETIME NOT NULL,
  result ENUM('completed','retry','failed','abandoned','cancelled') NOT NULL, message VARCHAR(255) NULL, KEY idx_jel_job (job_id, id),
  CONSTRAINT fk_jel_job FOREIGN KEY (job_id) REFERENCES scheduled_jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS scheduler_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, source ENUM('cli','http') NOT NULL, worker VARCHAR(64) NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME NULL,
  events_processed INT UNSIGNED NOT NULL DEFAULT 0, jobs_processed INT UNSIGNED NOT NULL DEFAULT 0, recovered INT UNSIGNED NOT NULL DEFAULT 0, error VARCHAR(255) NULL, KEY idx_sr_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS scheduled_followups (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, entity_type ENUM('contact','trip','booking','invoice') NOT NULL, entity_id INT UNSIGNED NOT NULL, assigned_to INT UNSIGNED NULL,
  scheduled_at DATETIME NOT NULL, timezone VARCHAR(64) NOT NULL, action ENUM('create_task','notify','queue_email') NOT NULL, payload_json TEXT NOT NULL,
  status ENUM('pending','completed','cancelled','failed') NOT NULL DEFAULT 'pending', job_id BIGINT UNSIGNED NULL, workflow_execution_id INT UNSIGNED NULL, result VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL, executed_at DATETIME NULL, KEY idx_fu_due (status, scheduled_at), KEY idx_fu_entity (entity_type, entity_id),
  CONSTRAINT fk_fu_user FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL, CONSTRAINT fk_fu_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- notifications (existing) and activity timeline
ALTER TABLE notifications ADD COLUMN category VARCHAR(40) NOT NULL DEFAULT 'general', ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'event', ADD COLUMN dismissed_at DATETIME NULL, ADD COLUMN dedupe_key VARCHAR(190) NULL UNIQUE;
CREATE TABLE IF NOT EXISTS activity_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, entity_type VARCHAR(20) NOT NULL, entity_id INT UNSIGNED NOT NULL, event_type VARCHAR(40) NOT NULL, actor_id INT UNSIGNED NULL,
  summary VARCHAR(255) NOT NULL, meta_json TEXT NULL, created_at DATETIME NOT NULL, KEY idx_ae_entity (entity_type, entity_id, id), KEY idx_ae_created (created_at),
  CONSTRAINT fk_ae_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

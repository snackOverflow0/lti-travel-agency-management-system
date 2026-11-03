-- 010: library sections, new template kinds, and import provenance (origin + unique source_key) so the
-- Revision 27 library import is idempotent and imported records stay identifiable. Additive; no data changed.
ALTER TABLE content_folders ADD COLUMN section VARCHAR(30) NOT NULL DEFAULT 'general' AFTER parent_id, ADD COLUMN origin VARCHAR(30) NULL, ADD COLUMN source_key VARCHAR(160) NULL, ADD UNIQUE KEY uq_cf_source (source_key), ADD KEY idx_cf_section (section, parent_id);
ALTER TABLE content_templates MODIFY kind ENUM('itinerary','activity','checklist','supplier_info','document','guide','task','workflow_reference') NOT NULL,
  ADD COLUMN origin VARCHAR(30) NULL, ADD COLUMN source_key VARCHAR(160) NULL, ADD UNIQUE KEY uq_ct_source (source_key);
ALTER TABLE email_templates ADD COLUMN origin VARCHAR(30) NULL, ADD COLUMN source_key VARCHAR(160) NULL, ADD UNIQUE KEY uq_et_source (source_key);
CREATE TABLE IF NOT EXISTS library_import_runs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, source VARCHAR(60) NOT NULL, source_sha256 CHAR(64) NOT NULL, dry_run TINYINT(1) NOT NULL, run_by INT UNSIGNED NULL,
  summary_json TEXT NOT NULL, created_at DATETIME NOT NULL, CONSTRAINT fk_lir_user FOREIGN KEY (run_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

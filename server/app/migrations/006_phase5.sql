-- 006 Phase 5: quotes, invoices, payments, allocations, schedules, refunds, supplier payables, commissions,
-- currencies and exchange rates, financial audit chain, idempotency. Additive; existing rows are preserved.
INSERT IGNORE INTO permissions (code, description) VALUES
('finance.invoice','Create and issue quotes and invoices'),('finance.void','Void invoices and reverse payments');
INSERT IGNORE INTO role_permissions SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.code IN ('owner','admin','supervisor','advisor','finance') AND p.code='finance.invoice';
INSERT IGNORE INTO role_permissions SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.code IN ('owner','admin','finance') AND p.code='finance.void';
INSERT IGNORE INTO role_permissions SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.code='operations' AND p.code='finance.view';
CREATE TABLE IF NOT EXISTS currencies (
  code CHAR(3) PRIMARY KEY, name VARCHAR(60) NOT NULL, minor_units TINYINT UNSIGNED NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO currencies (code, name, minor_units) VALUES ('USD','US dollar',2),('EUR','Euro',2),('GBP','Pound sterling',2),('CAD','Canadian dollar',2),('AUD','Australian dollar',2),
('NZD','New Zealand dollar',2),('CHF','Swiss franc',2),('JPY','Japanese yen',0),('MXN','Mexican peso',2),('PHP','Philippine peso',2),('ZAR','South African rand',2),('KES','Kenyan shilling',2),('SGD','Singapore dollar',2),('HKD','Hong Kong dollar',2),('AED','UAE dirham',2),('KWD','Kuwaiti dinar',3);
-- Amount columns hold two decimals, so currencies with three minor units are kept inactive (documented limitation).
UPDATE currencies SET is_active=0 WHERE minor_units > 2;
CREATE TABLE IF NOT EXISTS exchange_rates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, from_currency CHAR(3) NOT NULL, to_currency CHAR(3) NOT NULL, rate DECIMAL(20,8) NOT NULL,
  effective_date DATE NOT NULL, source_note VARCHAR(190) NOT NULL, created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL,
  UNIQUE KEY uq_rate (from_currency, to_currency, effective_date), KEY idx_rate_lookup (from_currency, to_currency, effective_date),
  CONSTRAINT fk_er_from FOREIGN KEY (from_currency) REFERENCES currencies(code), CONSTRAINT fk_er_to FOREIGN KEY (to_currency) REFERENCES currencies(code),
  CONSTRAINT fk_er_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(60) PRIMARY KEY, setting_value TEXT NOT NULL, updated_by INT UNSIGNED NULL, updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO app_settings (setting_key, setting_value, updated_at) VALUES ('reporting_currency','USD',UTC_TIMESTAMP());
CREATE TABLE IF NOT EXISTS tax_rates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, rate DECIMAL(7,4) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE trips MODIFY currency CHAR(3) NOT NULL DEFAULT 'USD';
ALTER TABLE bookings ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'USD' AFTER amount;
-- quotes (existing table `quotations`)
ALTER TABLE quotations MODIFY status ENUM('draft','sent','accepted','declined','expired','viewed','rejected','archived') NOT NULL DEFAULT 'draft';
UPDATE quotations SET status='rejected' WHERE status='declined';
ALTER TABLE quotations
  MODIFY status ENUM('draft','sent','viewed','accepted','rejected','expired','archived') NOT NULL DEFAULT 'draft',
  MODIFY total DECIMAL(14,2) NOT NULL DEFAULT 0,
  ADD COLUMN title VARCHAR(190) NULL AFTER number,
  ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'USD',
  ADD COLUMN subtotal DECIMAL(14,2) NOT NULL DEFAULT 0, ADD COLUMN discount_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none', ADD COLUMN discount_value DECIMAL(14,4) NOT NULL DEFAULT 0,
  ADD COLUMN discount_total DECIMAL(14,2) NOT NULL DEFAULT 0, ADD COLUMN tax_rate_id INT UNSIGNED NULL, ADD COLUMN tax_name VARCHAR(80) NULL, ADD COLUMN tax_rate DECIMAL(7,4) NOT NULL DEFAULT 0,
  ADD COLUMN tax_total DECIMAL(14,2) NOT NULL DEFAULT 0, ADD COLUMN terms TEXT NULL, ADD COLUMN notes TEXT NULL,
  ADD COLUMN sent_at DATETIME NULL, ADD COLUMN decided_at DATETIME NULL, ADD COLUMN archived_at DATETIME NULL, ADD COLUMN duplicated_from INT UNSIGNED NULL,
  ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1,
  ADD KEY idx_q_status (status), ADD KEY idx_q_trip (trip_id), ADD KEY idx_q_contact (contact_id),
  ADD CONSTRAINT fk_q_currency FOREIGN KEY (currency) REFERENCES currencies(code), ADD CONSTRAINT fk_q_tax FOREIGN KEY (tax_rate_id) REFERENCES tax_rates(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_q_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;
CREATE TABLE IF NOT EXISTS quote_acceptances (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, quote_id INT UNSIGNED NOT NULL UNIQUE, decision ENUM('accepted','rejected') NOT NULL,
  method ENUM('recorded_by_staff') NOT NULL DEFAULT 'recorded_by_staff', client_name VARCHAR(160) NULL, note VARCHAR(500) NULL,
  snapshot_json MEDIUMTEXT NULL, snapshot_sha256 CHAR(64) NULL, recorded_by INT UNSIGNED NULL, created_at DATETIME NOT NULL,
  CONSTRAINT fk_qa_quote FOREIGN KEY (quote_id) REFERENCES quotations(id), CONSTRAINT fk_qa_user FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- line items (existing table `document_lines`, shared by quotes and invoices)
ALTER TABLE document_lines
  MODIFY quantity DECIMAL(10,2) NOT NULL DEFAULT 1, MODIFY unit_amount DECIMAL(14,2) NOT NULL,
  ADD COLUMN supplier_id INT UNSIGNED NULL, ADD COLUMN is_taxable TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN line_total DECIMAL(14,2) NOT NULL DEFAULT 0, ADD COLUMN sort_order SMALLINT NOT NULL DEFAULT 0,
  ADD KEY idx_dl_quote (quotation_id, sort_order), ADD KEY idx_dl_invoice (invoice_id, sort_order),
  ADD CONSTRAINT fk_dl_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL;
-- invoices
ALTER TABLE invoices MODIFY status ENUM('draft','sent','paid','void','issued','partially_paid') NOT NULL DEFAULT 'draft';
UPDATE invoices SET status='issued' WHERE status='sent';
ALTER TABLE invoices
  MODIFY status ENUM('draft','issued','partially_paid','paid','void') NOT NULL DEFAULT 'draft',
  MODIFY issue_date DATE NULL, MODIFY total DECIMAL(14,2) NOT NULL DEFAULT 0,
  ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'USD', ADD COLUMN quote_id INT UNSIGNED NULL UNIQUE, ADD COLUMN booking_id INT UNSIGNED NULL,
  ADD COLUMN subtotal DECIMAL(14,2) NOT NULL DEFAULT 0, ADD COLUMN discount_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none', ADD COLUMN discount_value DECIMAL(14,4) NOT NULL DEFAULT 0,
  ADD COLUMN discount_total DECIMAL(14,2) NOT NULL DEFAULT 0, ADD COLUMN tax_name VARCHAR(80) NULL, ADD COLUMN tax_rate DECIMAL(7,4) NOT NULL DEFAULT 0, ADD COLUMN tax_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  ADD COLUMN adjustments_total DECIMAL(14,2) NOT NULL DEFAULT 0, ADD COLUMN amount_paid DECIMAL(14,2) NOT NULL DEFAULT 0, ADD COLUMN balance DECIMAL(14,2) NOT NULL DEFAULT 0,
  ADD COLUMN payment_terms VARCHAR(190) NULL, ADD COLUMN terms TEXT NULL, ADD COLUMN notes TEXT NULL,
  ADD COLUMN issued_at DATETIME NULL, ADD COLUMN voided_at DATETIME NULL, ADD COLUMN void_reason VARCHAR(255) NULL, ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1,
  ADD KEY idx_inv_status (status, due_date), ADD KEY idx_inv_trip (trip_id), ADD KEY idx_inv_contact (contact_id),
  ADD CONSTRAINT fk_inv_currency FOREIGN KEY (currency) REFERENCES currencies(code), ADD CONSTRAINT fk_inv_quote FOREIGN KEY (quote_id) REFERENCES quotations(id),
  ADD CONSTRAINT fk_inv_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL, ADD CONSTRAINT fk_inv_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;
CREATE TABLE IF NOT EXISTS invoice_sequences ( seq_year SMALLINT UNSIGNED PRIMARY KEY, next_value INT UNSIGNED NOT NULL ) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS invoice_adjustments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, invoice_id INT UNSIGNED NOT NULL, amount DECIMAL(14,2) NOT NULL, reason VARCHAR(255) NOT NULL, created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL,
  KEY idx_ia_inv (invoice_id), CONSTRAINT fk_ia_inv FOREIGN KEY (invoice_id) REFERENCES invoices(id), CONSTRAINT fk_ia_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- installments (existing table `payment_schedules`)
ALTER TABLE payment_schedules
  ADD COLUMN invoice_id INT UNSIGNED NULL, ADD COLUMN is_deposit TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN sort_order SMALLINT NOT NULL DEFAULT 0, ADD COLUMN created_by INT UNSIGNED NULL,
  ADD KEY idx_ps_invoice (invoice_id, sort_order), ADD CONSTRAINT fk_ps_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id);
-- client payments (existing table `payments`)
ALTER TABLE payments
  MODIFY amount DECIMAL(14,2) NOT NULL,
  ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'USD', ADD COLUMN contact_id INT UNSIGNED NULL, ADD COLUMN trip_id INT UNSIGNED NULL,
  ADD COLUMN status ENUM('recorded','reversed') NOT NULL DEFAULT 'recorded', ADD COLUMN verification ENUM('unverified','confirmed_by_staff') NOT NULL DEFAULT 'unverified', ADD COLUMN verification_note VARCHAR(255) NULL,
  ADD COLUMN reversed_at DATETIME NULL, ADD COLUMN reversed_by INT UNSIGNED NULL, ADD COLUMN reversal_reason VARCHAR(255) NULL, ADD COLUMN idempotency_key VARCHAR(80) NULL UNIQUE,
  ADD KEY idx_pay_invoice (invoice_id, status), ADD KEY idx_pay_date (received_on),
  ADD CONSTRAINT fk_pay_currency FOREIGN KEY (currency) REFERENCES currencies(code), ADD CONSTRAINT fk_pay_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_pay_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE SET NULL, ADD CONSTRAINT fk_pay_recorder FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_pay_reverser FOREIGN KEY (reversed_by) REFERENCES users(id) ON DELETE SET NULL;
CREATE TABLE IF NOT EXISTS payment_allocations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, payment_id INT UNSIGNED NOT NULL, installment_id INT UNSIGNED NOT NULL, amount DECIMAL(14,2) NOT NULL,
  UNIQUE KEY uq_alloc (payment_id, installment_id), KEY idx_alloc_inst (installment_id),
  CONSTRAINT fk_alloc_pay FOREIGN KEY (payment_id) REFERENCES payments(id), CONSTRAINT fk_alloc_inst FOREIGN KEY (installment_id) REFERENCES payment_schedules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS refunds (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, payment_id INT UNSIGNED NOT NULL, amount DECIMAL(14,2) NOT NULL, refunded_on DATE NOT NULL,
  method ENUM('bank_transfer','cash','check','card_external','other') NOT NULL, reference VARCHAR(100) NULL, reason VARCHAR(255) NOT NULL,
  idempotency_key VARCHAR(80) NULL UNIQUE, recorded_by INT UNSIGNED NULL, created_at DATETIME NOT NULL,
  KEY idx_ref_pay (payment_id), CONSTRAINT fk_ref_pay FOREIGN KEY (payment_id) REFERENCES payments(id), CONSTRAINT fk_ref_user FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- supplier payables
CREATE TABLE IF NOT EXISTS supplier_payables (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, booking_id INT UNSIGNED NOT NULL, supplier_id INT UNSIGNED NOT NULL, currency CHAR(3) NOT NULL, amount DECIMAL(14,2) NOT NULL,
  supplier_invoice_ref VARCHAR(100) NOT NULL DEFAULT '', due_date DATE NULL, status ENUM('open','partially_paid','paid','void') NOT NULL DEFAULT 'open', notes TEXT NULL,
  paid_total DECIMAL(14,2) NOT NULL DEFAULT 0, balance DECIMAL(14,2) NOT NULL DEFAULT 0, created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, version INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_payable_ref (booking_id, supplier_invoice_ref), KEY idx_sp_supplier (supplier_id, status), KEY idx_sp_due (due_date),
  CONSTRAINT fk_spay_booking FOREIGN KEY (booking_id) REFERENCES bookings(id), CONSTRAINT fk_spay_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
  CONSTRAINT fk_spay_currency FOREIGN KEY (currency) REFERENCES currencies(code), CONSTRAINT fk_spay_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS supplier_payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, payable_id INT UNSIGNED NOT NULL, kind ENUM('payment','credit') NOT NULL DEFAULT 'payment', amount DECIMAL(14,2) NOT NULL, paid_on DATE NOT NULL,
  method ENUM('bank_transfer','cash','check','card_external','other') NOT NULL DEFAULT 'bank_transfer', reference VARCHAR(100) NULL, notes VARCHAR(255) NULL,
  status ENUM('recorded','reversed') NOT NULL DEFAULT 'recorded', reversed_at DATETIME NULL, reversal_reason VARCHAR(255) NULL,
  idempotency_key VARCHAR(80) NULL UNIQUE, recorded_by INT UNSIGNED NULL, created_at DATETIME NOT NULL,
  KEY idx_spm_payable (payable_id, status), CONSTRAINT fk_spm_payable FOREIGN KEY (payable_id) REFERENCES supplier_payables(id), CONSTRAINT fk_spm_user FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- commissions
CREATE TABLE IF NOT EXISTS commission_rules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, supplier_id INT UNSIGNED NULL, booking_category VARCHAR(30) NULL,
  basis ENUM('percent','flat') NOT NULL, value DECIMAL(14,4) NOT NULL, currency CHAR(3) NULL, advisor_share_pct DECIMAL(7,4) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1, created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
  KEY idx_cr_match (is_active, supplier_id, booking_category),
  CONSTRAINT fk_cr_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id), CONSTRAINT fk_cr_currency FOREIGN KEY (currency) REFERENCES currencies(code),
  CONSTRAINT fk_cr_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE commissions
  MODIFY expected DECIMAL(14,2) NOT NULL, MODIFY received DECIMAL(14,2) NOT NULL DEFAULT 0,
  MODIFY status ENUM('expected','matched','partially_received','paid_out','cancelled','earned','received','payable','paid','reversed') NOT NULL DEFAULT 'expected';
UPDATE commissions SET status = CASE status WHEN 'matched' THEN 'received' WHEN 'partially_received' THEN 'received' WHEN 'paid_out' THEN 'paid' WHEN 'cancelled' THEN 'reversed' ELSE status END;
ALTER TABLE commissions
  MODIFY status ENUM('expected','earned','received','payable','paid','reversed') NOT NULL DEFAULT 'expected',
  ADD COLUMN rule_id INT UNSIGNED NULL, ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'USD', ADD COLUMN basis ENUM('percent','flat') NOT NULL DEFAULT 'percent', ADD COLUMN rate_value DECIMAL(14,4) NOT NULL DEFAULT 0,
  ADD COLUMN base_amount DECIMAL(14,2) NOT NULL DEFAULT 0, ADD COLUMN is_override TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN advisor_id INT UNSIGNED NULL, ADD COLUMN advisor_share_pct DECIMAL(7,4) NOT NULL DEFAULT 0, ADD COLUMN advisor_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  ADD COLUMN adjustments_total DECIMAL(14,2) NOT NULL DEFAULT 0, ADD COLUMN advisor_adjustments DECIMAL(14,2) NOT NULL DEFAULT 0, ADD COLUMN advisor_paid DECIMAL(14,2) NOT NULL DEFAULT 0,
  ADD COLUMN earned_at DATETIME NULL, ADD COLUMN reversed_at DATETIME NULL, ADD COLUMN reversal_reason VARCHAR(255) NULL,
  ADD COLUMN created_by INT UNSIGNED NULL, ADD COLUMN created_at DATETIME NULL, ADD COLUMN updated_at DATETIME NULL, ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1,
  ADD KEY idx_com_advisor (advisor_id, status), ADD KEY idx_com_status (status),
  ADD CONSTRAINT fk_com_rule FOREIGN KEY (rule_id) REFERENCES commission_rules(id) ON DELETE SET NULL, ADD CONSTRAINT fk_com_currency FOREIGN KEY (currency) REFERENCES currencies(code),
  ADD CONSTRAINT fk_com_advisor FOREIGN KEY (advisor_id) REFERENCES users(id) ON DELETE SET NULL;
CREATE TABLE IF NOT EXISTS commission_receipts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, commission_id INT UNSIGNED NOT NULL, amount DECIMAL(14,2) NOT NULL, received_on DATE NOT NULL, reference VARCHAR(100) NULL,
  status ENUM('recorded','reversed') NOT NULL DEFAULT 'recorded', reversed_at DATETIME NULL, reversal_reason VARCHAR(255) NULL,
  idempotency_key VARCHAR(80) NULL UNIQUE, recorded_by INT UNSIGNED NULL, created_at DATETIME NOT NULL,
  KEY idx_crc_com (commission_id, status), CONSTRAINT fk_crc_com FOREIGN KEY (commission_id) REFERENCES commissions(id), CONSTRAINT fk_crc_user FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS advisor_payouts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, commission_id INT UNSIGNED NOT NULL, advisor_id INT UNSIGNED NOT NULL, amount DECIMAL(14,2) NOT NULL, paid_on DATE NOT NULL, reference VARCHAR(100) NULL,
  status ENUM('recorded','reversed') NOT NULL DEFAULT 'recorded', reversed_at DATETIME NULL, reversal_reason VARCHAR(255) NULL,
  idempotency_key VARCHAR(80) NULL UNIQUE, recorded_by INT UNSIGNED NULL, created_at DATETIME NOT NULL,
  KEY idx_ap_com (commission_id, status), KEY idx_ap_adv (advisor_id, paid_on),
  CONSTRAINT fk_ap_com FOREIGN KEY (commission_id) REFERENCES commissions(id), CONSTRAINT fk_ap_adv FOREIGN KEY (advisor_id) REFERENCES users(id), CONSTRAINT fk_ap_user FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS commission_adjustments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, commission_id INT UNSIGNED NOT NULL, target ENUM('agency','advisor') NOT NULL, amount DECIMAL(14,2) NOT NULL, reason VARCHAR(255) NOT NULL,
  created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL, KEY idx_cadj_com (commission_id),
  CONSTRAINT fk_cadj_com FOREIGN KEY (commission_id) REFERENCES commissions(id), CONSTRAINT fk_cadj_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- tamper-evident financial audit chain and idempotency
CREATE TABLE IF NOT EXISTS financial_audit_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, actor_id INT UNSIGNED NULL, action VARCHAR(60) NOT NULL, entity_type VARCHAR(30) NOT NULL, entity_id INT UNSIGNED NOT NULL,
  before_json MEDIUMTEXT NULL, after_json MEDIUMTEXT NULL, reason VARCHAR(255) NULL, created_at DATETIME NOT NULL, prev_hash CHAR(64) NOT NULL, hash CHAR(64) NOT NULL,
  KEY idx_fae_entity (entity_type, entity_id), KEY idx_fae_actor (actor_id, created_at),
  CONSTRAINT fk_fae_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS idempotency_keys (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, idem_key VARCHAR(80) NOT NULL, request_path VARCHAR(190) NOT NULL, request_hash CHAR(64) NOT NULL,
  status_code SMALLINT NULL, response_json MEDIUMTEXT NULL, created_at DATETIME NOT NULL,
  UNIQUE KEY uq_idem (user_id, idem_key), CONSTRAINT fk_idem_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

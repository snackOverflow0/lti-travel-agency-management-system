-- 011 Phase 9: agency settings defaults, tag provenance, booking requirements. Additive; existing values kept (INSERT IGNORE).
ALTER TABLE tags ADD COLUMN origin VARCHAR(30) NULL, ADD COLUMN created_at DATETIME NULL;
INSERT IGNORE INTO app_settings (setting_key, setting_value, updated_at) VALUES
('agency_email','',UTC_TIMESTAMP()),('agency_phone','',UTC_TIMESTAMP()),('agency_address','',UTC_TIMESTAMP()),('agency_website','',UTC_TIMESTAMP()),
('brand_color','#c19a3b',UTC_TIMESTAMP()),('date_format','MMM D, YYYY',UTC_TIMESTAMP()),('week_start','Sun',UTC_TIMESTAMP()),('email_signature','',UTC_TIMESTAMP()),
('terms_text','',UTC_TIMESTAMP()),('terms_published_at','',UTC_TIMESTAMP()),('booking_required_fields','[]',UTC_TIMESTAMP());

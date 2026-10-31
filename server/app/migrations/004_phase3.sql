-- 004 Phase 3: trips, travelers, itineraries, publishing and client portal. Additive only (no data changes to existing rows).
ALTER TABLE trips
  ADD COLUMN created_by INT UNSIGNED NULL AFTER owner_id,
  ADD COLUMN notes TEXT NULL,
  ADD COLUMN tags_json TEXT NULL,
  ADD COLUMN archived_at DATETIME NULL,
  ADD COLUMN published_version_id INT UNSIGNED NULL,
  ADD COLUMN selected_option_id INT UNSIGNED NULL,
  ADD COLUMN itinerary_rev INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN itinerary_meta_json MEDIUMTEXT NULL,
  ADD KEY idx_trips_contact (primary_contact_id),
  ADD KEY idx_trips_archived (archived_at),
  ADD CONSTRAINT fk_trips_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_trips_pubver FOREIGN KEY (published_version_id) REFERENCES itinerary_versions(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_trips_selopt FOREIGN KEY (selected_option_id) REFERENCES itinerary_options(id) ON DELETE SET NULL;
ALTER TABLE itinerary_activities MODIFY details_json MEDIUMTEXT NULL;
ALTER TABLE itinerary_versions ADD COLUMN draft_hash CHAR(64) NULL;
ALTER TABLE trip_approvals
  MODIFY action ENUM('approved','changes_requested','comment','option_selected') NOT NULL,
  ADD COLUMN user_id INT UNSIGNED NULL,
  ADD COLUMN author_name VARCHAR(160) NULL,
  ADD KEY idx_ta_trip (trip_id, created_at),
  ADD CONSTRAINT fk_ta_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;

-- 009 Phase 8: performance indexes (additive, no data changes). Measured before/after in docs/PHASE8_PERFORMANCE.md.
ALTER TABLE tasks ADD KEY idx_tasks_assignee_status_due (assigned_to, status, due_date);
ALTER TABLE calls ADD KEY idx_calls_staff_started (staff_id, started_at);
ALTER TABLE login_attempts ADD KEY idx_la_ip_time (ip, created_at);

<?php
declare(strict_types=1);
namespace LTI\Controllers;
use LTI\{Auth, Audit, Db, Events, Http, HttpError, Jobs, Phone, Validate, Workflow};
/** Call management without a telephony provider. Records are either a manual log (entered by staff) or a phone-app handoff
 *  (LTI asked the device to dial; whether it connected is unknown until staff record the outcome). No call is ever marked
 *  connected automatically and no duration is ever invented. */
final class CallController {
    const STATUSES = ['connected', 'no_answer', 'busy', 'voicemail_left', 'missed', 'failed'];
    const LABELS = ['attempted' => 'Dial handoff — outcome not recorded', 'connected' => 'Connected', 'no_answer' => 'No answer', 'busy' => 'Busy', 'voicemail_left' => 'Voicemail left', 'missed' => 'Missed', 'failed' => 'Failed', 'cancelled' => 'Cancelled'];
    const SOURCES = ['manual_log' => 'Manual log (entered by staff)', 'external_handoff' => 'Phone-app handoff', 'webrtc_verified' => 'Browser call (verified)', 'provider_verified' => 'Provider record (verified)'];
    const VERIFY = ['user_entered' => 'Entered by staff — not verified by a phone network', 'handoff_unconfirmed' => 'Handoff only — connection not confirmed', 'verified' => 'Verified'];
    public static function capability(): array {
        Auth::require('calls.use');
        return ['activeModes' => ['external_dialing', 'manual_logging'], 'pstn' => ['available' => false, 'reason' => 'No telephony provider is configured. Website hosting does not include phone service, and no paid service may be added.'],
            'modes' => [
                ['code' => 'external_dialing', 'available' => true, 'label' => 'External dialing', 'detail' => 'Opens your phone or calling app with a tel: link (if your device has one). LTI cannot see whether the call connects; you record the outcome.'],
                ['code' => 'manual_logging', 'available' => true, 'label' => 'Manual call logging', 'detail' => 'Record calls made or received on any phone, with notes, outcome and callbacks.'],
                ['code' => 'provider', 'available' => false, 'label' => 'Telephony provider', 'detail' => 'Not configured. Would require an existing compatible service already paid for by the agency.'],
                ['code' => 'webrtc', 'available' => false, 'label' => 'Browser-to-browser calling', 'detail' => 'Not implemented: reliable calls need a TURN relay server, which has no free hosted option. It would not dial phone numbers.']],
            'unavailableControls' => ['answer', 'hold', 'mute', 'transfer', 'record', 'voicemail'], 'recording' => 'Not available. Calls are never recorded by LTI.', 'defaultCountry' => Phone::defaultCountry()];
    }
    private static function scope(array $u): array { if (Auth::can('calls.view_all')) return ['1=1', []]; [$cw, $cp] = ContactController::scope($u);
        return ["(cl.staff_id=? OR cl.created_by=? OR (cl.contact_id IS NOT NULL AND EXISTS (SELECT 1 FROM contacts c WHERE c.id=cl.contact_id AND $cw)))", array_merge([(int)$u['id'], (int)$u['id']], $cp)]; }
    private static function find(array $u, int $id): array { [$w, $p] = self::scope($u); $r = Db::one("SELECT cl.*, s.full_name staff_name, d.code disp_code, d.label disp_label FROM calls cl LEFT JOIN users s ON s.id=cl.staff_id LEFT JOIN call_dispositions d ON d.id=cl.disposition_id WHERE cl.id=? AND $w", array_merge([$id], $p)); if (!$r) throw new HttpError(404, 'Call not found'); return $r; }
    private static function contactLabel(array $u, ?int $id): ?array { if (!$id) return null; try { $c = ContactController::find($u, $id); return ['id' => $id, 'label' => trim($c['first_name'] . ' ' . $c['last_name'])]; } catch (HttpError $e) { return ['id' => null, 'label' => 'Restricted record']; } }
    private static function tripLabel(array $u, ?int $id): ?array { if (!$id) return null; try { $t = TripController::findRow($u, $id); return ['id' => $id, 'label' => $t['name']]; } catch (HttpError $e) { return ['id' => null, 'label' => 'Restricted record']; } }
    public static function out(array $u, array $r, bool $full = false): array {
        $tz = Workflow::agencyTz(); $loc = function ($v) use ($tz) { return $v ? (new \DateTime($v, new \DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d H:i') : null; };
        $n = $r['phone'] ? Phone::normalize($r['phone']) : null;
        $o = ['id' => (int)$r['id'], 'direction' => $r['direction'], 'status' => $r['status'], 'statusLabel' => self::LABELS[$r['status']] ?? $r['status'], 'source' => $r['source'], 'sourceLabel' => self::SOURCES[$r['source']],
            'verification' => $r['verification'], 'verificationLabel' => self::VERIFY[$r['verification']], 'phone' => $r['phone'], 'phoneDisplay' => $n ? $n['display'] : null, 'phoneE164' => $r['phone_e164'],
            'contact' => self::contactLabel($u, $r['contact_id'] ? (int)$r['contact_id'] : null), 'trip' => self::tripLabel($u, $r['trip_id'] ? (int)$r['trip_id'] : null), 'bookingId' => $r['booking_id'] === null ? null : (int)$r['booking_id'],
            'staffId' => $r['staff_id'] === null ? null : (int)$r['staff_id'], 'staffName' => $r['staff_name'] ?? null, 'startedAt' => $r['started_at'], 'startedAtLocal' => $loc($r['started_at']), 'endedAt' => $r['ended_at'],
            'durationSeconds' => $r['duration_seconds'] === null ? null : (int)$r['duration_seconds'], 'durationSource' => $r['duration_source'],
            'disposition' => $r['disposition_id'] ? ['id' => (int)$r['disposition_id'], 'code' => $r['disp_code'], 'label' => $r['disp_label']] : null, 'archived' => $r['archived_at'] !== null, 'version' => (int)$r['version'], 'timezone' => $tz->getName()];
        if ($full) { $o['notes'] = array_map(function ($x) { return ['id' => (int)$x['id'], 'body' => $x['body'], 'by' => $x['full_name'], 'userId' => $x['user_id'] === null ? null : (int)$x['user_id'], 'at' => $x['created_at'], 'editedAt' => $x['updated_at']]; }, Db::all('SELECT n.*, u.full_name FROM call_notes n LEFT JOIN users u ON u.id=n.user_id WHERE n.call_id=? ORDER BY n.id', [$r['id']]));
            $o['history'] = array_map(function ($x) { return ['event' => $x['event'], 'by' => $x['full_name'], 'at' => $x['created_at']]; }, Db::all('SELECT e.*, u.full_name FROM call_events e LEFT JOIN users u ON u.id=e.user_id WHERE e.call_id=? ORDER BY e.id', [$r['id']]));
            $o['callbacks'] = CallbackController::forCall($u, (int)$r['id']); }
        return $o;
    }
    private static function filters(array $u): array {
        [$w, $p] = self::scope($u); $where = [$w]; $g = $_GET;
        if (empty($g['archived'])) $where[] = 'cl.archived_at IS NULL';
        if (($d = (string)($g['direction'] ?? '')) !== '') { Validate::enum(['d' => $d], 'd', ['inbound', 'outbound']); $where[] = 'cl.direction=?'; $p[] = $d; }
        $st = (string)($g['status'] ?? ''); if ($st === 'missed') $where[] = "cl.status IN ('missed','no_answer')"; elseif ($st === 'connected') $where[] = "cl.status='connected'"; elseif ($st === 'followup') $where[] = '(d.requires_followup=1 OR EXISTS (SELECT 1 FROM callbacks cb WHERE cb.call_id=cl.id AND cb.status=\'pending\'))'; elseif ($st === 'attempted') $where[] = "cl.status='attempted'";
        foreach (['staffId' => 'cl.staff_id', 'contactId' => 'cl.contact_id', 'tripId' => 'cl.trip_id'] as $k => $c) if (($v = (int)($g[$k] ?? 0)) > 0) { $where[] = "$c=?"; $p[] = $v; }
        if (($f = Validate::date($g, 'from'))) { $where[] = 'cl.started_at>=?'; $p[] = $f . ' 00:00:00'; } if (($t = Validate::date($g, 'to'))) { $where[] = 'cl.started_at<=?'; $p[] = $t . ' 23:59:59'; }
        if (($q = trim((string)($g['q'] ?? ''))) !== '') { $digits = preg_replace('/\D/', '', $q); $l = '%' . addcslashes($q, '%_\\') . '%'; [$cw, $cp] = ContactController::scope($u);
            $parts = ["EXISTS (SELECT 1 FROM contacts c WHERE c.id=cl.contact_id AND $cw AND CONCAT_WS(' ', c.first_name, c.last_name) LIKE ?)"]; $p = array_merge($p, $cp, [$l]);
            if (strlen($digits) >= 4) { $parts[] = 'REPLACE(cl.phone_e164,\'+\',\'\') LIKE ?'; $p[] = '%' . $digits . '%'; } $where[] = '(' . implode(' OR ', $parts) . ')'; }
        return [implode(' AND ', $where), $p];
    }
    public static function list(): array { $u = Auth::require('calls.use'); [$w, $p] = self::filters($u); $page = max(1, (int)($_GET['page'] ?? 1)); $per = min(200, max(1, (int)($_GET['perPage'] ?? 25))); $dir = ($_GET['sort'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        $from = "FROM calls cl LEFT JOIN users s ON s.id=cl.staff_id LEFT JOIN call_dispositions d ON d.id=cl.disposition_id WHERE $w";
        return ['total' => (int)Db::one("SELECT COUNT(*) n $from", $p)['n'], 'page' => $page, 'perPage' => $per, 'items' => array_map(function ($r) use ($u) { return self::out($u, $r); }, Db::all("SELECT cl.*, s.full_name staff_name, d.code disp_code, d.label disp_label $from ORDER BY cl.started_at $dir, cl.id $dir LIMIT $per OFFSET " . (($page - 1) * $per), $p))]; }
    public static function get(array $pa): array { $u = Auth::require('calls.use'); return ['call' => self::out($u, self::find($u, (int)$pa['id']), true)]; }
    private static function links(array $u, array $d): array {
        $c = !empty($d['contactId']) ? (int)ContactController::find($u, (int)$d['contactId'])['id'] : null; $t = !empty($d['tripId']) ? (int)TripController::findRow($u, (int)$d['tripId'])['id'] : null; $b = null;
        if (!empty($d['bookingId'])) { $bk = Db::one('SELECT id, trip_id FROM bookings WHERE id=?', [(int)$d['bookingId']]); if (!$bk) throw new HttpError(422, Validate::MSG, ['bookingId' => 'Unknown booking']); TripController::findRow($u, (int)$bk['trip_id']); $b = (int)$bk['id']; $t = $t ?: (int)$bk['trip_id']; }
        return [$c, $t, $b];
    }
    private static function phone(array $d, ?int $contactId): array { $raw = Validate::str($d, 'phone', 40);
        if (!$raw && $contactId) { $p = Db::one('SELECT phone_raw FROM contact_phones WHERE contact_id=? ORDER BY is_preferred DESC, id LIMIT 1', [$contactId]); $raw = $p ? $p['phone_raw'] : null; }
        if (!$raw) return [null, null]; $n = Phone::normalize($raw, $d['country'] ?? null); if ($n['error']) throw new HttpError(422, Validate::MSG, ['phone' => $n['error']]); return [$raw, $n['e164']]; }
    /** Times are entered in the agency (or given) time zone and stored in UTC. Duration is only what staff enter (or the difference of entered start/end). */
    private static function times(array $d): array { $tz = !empty($d['timezone']) ? new \DateTimeZone((string)$d['timezone']) : Workflow::agencyTz(); $mk = function ($k) use ($d, $tz) { if (empty($d[$k])) return null; $x = \DateTime::createFromFormat('Y-m-d\TH:i', (string)$d[$k], $tz); if (!$x) throw new HttpError(422, Validate::MSG, [$k => 'Use date and time (YYYY-MM-DDTHH:MM)']); return $x->setTimezone(new \DateTimeZone('UTC')); };
        $s = $mk('startedAt') ?: new \DateTime('now', new \DateTimeZone('UTC')); $e = $mk('endedAt'); if ($s > new \DateTime('+5 minutes', new \DateTimeZone('UTC'))) throw new HttpError(422, Validate::MSG, ['startedAt' => 'Cannot be in the future']);
        $dur = isset($d['durationSeconds']) && $d['durationSeconds'] !== '' && $d['durationSeconds'] !== null ? (int)$d['durationSeconds'] : null;
        if ($dur !== null && ($dur < 0 || $dur > 86400)) throw new HttpError(422, Validate::MSG, ['durationSeconds' => 'Between 0 seconds and 24 hours']);
        if ($e) { if ($e < $s) throw new HttpError(422, Validate::MSG, ['endedAt' => 'Ends before it starts']); $calc = $e->getTimestamp() - $s->getTimestamp(); if ($dur !== null && abs($calc - $dur) > 60) throw new HttpError(422, Validate::MSG, ['durationSeconds' => 'Does not match the start and end times']); $dur = $dur ?? $calc; }
        return [$s->format('Y-m-d H:i:s'), $e ? $e->format('Y-m-d H:i:s') : null, $dur]; }
    private static function disposition(array $d): ?array { if (empty($d['dispositionId'])) return null; $x = Db::one('SELECT * FROM call_dispositions WHERE id=? AND is_active=1', [(int)$d['dispositionId']]); if (!$x) throw new HttpError(422, Validate::MSG, ['dispositionId' => 'Unknown or inactive outcome']); return $x; }
    private static function event(int $callId, ?int $uid, string $ev, $before = null, $after = null): void { Db::exec('INSERT INTO call_events (call_id, user_id, event, before_json, after_json, created_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())', [$callId, $uid, $ev, $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE), $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE)]); }
    private static function timeline(array $call, int $actor, string $summary, string $type = 'call.logged'): void { foreach (['contact_id' => 'contact', 'trip_id' => 'trip', 'booking_id' => 'booking'] as $c => $t) if (!empty($call[$c])) Events::activity($t, (int)$call[$c], $type, $actor, $summary, ['callId' => (int)$call['id']]); }
    private static function summary(array $c, ?array $disp): string { return ($c['direction'] === 'inbound' ? 'Inbound' : 'Outbound') . ' call ' . strtolower(self::LABELS[$c['status']] ?? $c['status']) . ($disp ? ' · ' . $disp['label'] : '') . ($c['duration_seconds'] !== null ? ' · ' . gmdate($c['duration_seconds'] >= 3600 ? 'G:i:s' : 'i:s', (int)$c['duration_seconds']) . ' (entered)' : ''); }
    /** Manual log of a call made or received on any phone. */
    public static function create(): array {
        $u = Auth::require('calls.use'); $d = Http::body(); [$c, $t, $b] = self::links($u, $d); [$raw, $e164] = self::phone($d, $c); if (!$raw && !$c) throw new HttpError(422, Validate::MSG, ['phone' => 'Enter a phone number or choose a contact']);
        $dir = Validate::enum($d, 'direction', ['inbound', 'outbound']); $st = Validate::enum($d, 'status', self::STATUSES); if (!$dir || !$st) throw new HttpError(422, Validate::MSG, ['status' => 'Choose direction and outcome']);
        if ($dir === 'outbound' && $st === 'missed') throw new HttpError(422, Validate::MSG, ['status' => 'Missed applies to inbound calls']);
        [$s, $e, $dur] = self::times($d); if ($st !== 'connected' && $dur) throw new HttpError(422, Validate::MSG, ['durationSeconds' => 'Only connected calls have a duration']); $disp = self::disposition($d);
        $id = Db::tx(function () use ($u, $d, $c, $t, $b, $raw, $e164, $dir, $st, $s, $e, $dur, $disp) {
            $id = Db::insert("INSERT INTO calls (mode, source, verification, direction, status, phone, phone_e164, contact_id, trip_id, booking_id, staff_id, started_at, ended_at, duration_seconds, duration_source, disposition_id, disposition, notes, created_by, created_at, updated_at, version) VALUES ('phone_handoff','manual_log','user_entered',?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1)",
                [$dir, $st, $raw, $e164, $c, $t, $b, (int)$u['id'], $s, $e, $dur, $dur === null ? 'unknown' : 'entered', $disp ? (int)$disp['id'] : null, $disp ? $disp['code'] : null, (int)$u['id']]);
            if ($note = Validate::str($d, 'notes', 5000)) Db::exec('INSERT INTO call_notes (call_id, user_id, body, created_at) VALUES (?,?,?,UTC_TIMESTAMP())', [$id, (int)$u['id'], $note]);
            $row = Db::one('SELECT * FROM calls WHERE id=?', [$id]); self::event($id, (int)$u['id'], 'logged', null, ['status' => $st, 'direction' => $dir]); self::timeline($row, (int)$u['id'], self::summary($row, $disp));
            Events::emit('call.logged', 'call', $id, (int)$u['id'], self::summary($row, $disp), ['direction' => $dir, 'status' => $st]); Audit::log((int)$u['id'], 'call.logged', 'call', $id); return $id; });
        return self::get(['id' => $id]);
    }
    /** Phone-app handoff: records an attempt only (no outcome, no duration) and returns the tel: URI. */
    public static function handoff(): array {
        $u = Auth::require('calls.use'); $d = Http::body(); [$c, $t, $b] = self::links($u, $d); [$raw, $e164] = self::phone($d, $c); if (!$e164) throw new HttpError(422, Validate::MSG, ['phone' => 'Enter a valid phone number']);
        $id = Db::insert("INSERT INTO calls (mode, source, verification, direction, status, phone, phone_e164, contact_id, trip_id, booking_id, staff_id, started_at, duration_source, created_by, created_at, updated_at, version) VALUES ('phone_handoff','external_handoff','handoff_unconfirmed','outbound','attempted',?,?,?,?,?,?,UTC_TIMESTAMP(),'unknown',?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1)",
            [$raw, $e164, $c, $t, $b, (int)$u['id'], (int)$u['id']]);
        self::event($id, (int)$u['id'], 'handoff_requested'); return ['call' => self::out($u, self::find($u, $id), true), 'telUri' => 'tel:' . $e164, 'note' => 'Your device was asked to dial. LTI cannot confirm whether the call connected. Record the outcome when you finish.'];
    }
    /** Staff record what actually happened after a handoff (or cancel it if no call was made). */
    public static function outcome(array $pa): array {
        $u = Auth::require('calls.use'); $d = Http::body();
        Db::tx(function () use ($u, $pa, $d) { $cur = self::find($u, (int)$pa['id']); if ($cur['status'] !== 'attempted') throw new HttpError(409, 'An outcome has already been recorded');
            if (!empty($d['cancel'])) { Db::exec("UPDATE calls SET status='cancelled', verification='user_entered', updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?", [$cur['id']]); self::event((int)$cur['id'], (int)$u['id'], 'handoff_cancelled'); return; }
            $st = Validate::enum($d, 'status', ['connected', 'no_answer', 'busy', 'voicemail_left', 'failed']); if (!$st) throw new HttpError(422, Validate::MSG, ['status' => 'Choose what happened']);
            $dur = isset($d['durationSeconds']) && $d['durationSeconds'] !== '' && $d['durationSeconds'] !== null ? (int)$d['durationSeconds'] : null; if ($dur !== null && ($dur < 0 || $dur > 86400)) throw new HttpError(422, Validate::MSG, ['durationSeconds' => 'Between 0 seconds and 24 hours']);
            if ($st !== 'connected' && $dur) throw new HttpError(422, Validate::MSG, ['durationSeconds' => 'Only connected calls have a duration']); $disp = self::disposition($d);
            Db::exec("UPDATE calls SET status=?, verification='user_entered', duration_seconds=?, duration_source=?, disposition_id=?, disposition=?, updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?", [$st, $dur, $dur === null ? 'unknown' : 'entered', $disp ? (int)$disp['id'] : null, $disp ? $disp['code'] : null, $cur['id']]);
            if ($note = Validate::str($d, 'notes', 5000)) Db::exec('INSERT INTO call_notes (call_id, user_id, body, created_at) VALUES (?,?,?,UTC_TIMESTAMP())', [$cur['id'], (int)$u['id'], $note]);
            $row = Db::one('SELECT * FROM calls WHERE id=?', [$cur['id']]); self::event((int)$cur['id'], (int)$u['id'], 'outcome_recorded', ['status' => 'attempted'], ['status' => $st]); self::timeline($row, (int)$u['id'], self::summary($row, $disp));
            Events::emit('call.logged', 'call', (int)$cur['id'], (int)$u['id'], self::summary($row, $disp), ['direction' => 'outbound', 'status' => $st]); });
        return self::get($pa);
    }
    private static function canEdit(array $u, array $c): void { if ((int)$c['staff_id'] !== (int)$u['id'] && (int)$c['created_by'] !== (int)$u['id'] && !Auth::can('calls.view_all')) throw new HttpError(403, 'Only the staff member who logged this call or a supervisor can change it');
        if (in_array($c['source'], ['webrtc_verified', 'provider_verified'], true)) throw new HttpError(409, 'Verified records cannot be edited'); }
    public static function update(array $pa): array {
        $u = Auth::require('calls.use'); $d = Http::body();
        Db::tx(function () use ($u, $pa, $d) { $cur = self::find($u, (int)$pa['id']); self::canEdit($u, $cur); if ($cur['status'] === 'attempted') throw new HttpError(409, 'Record the outcome first');
            if (isset($d['version']) && (int)$d['version'] !== (int)$cur['version']) throw new HttpError(409, 'This call was changed by someone else. Reload and try again.');
            $m = array_merge(['contactId' => $cur['contact_id'], 'tripId' => $cur['trip_id'], 'bookingId' => $cur['booking_id']], $d); [$c, $t, $b] = self::links($u, $m);
            $st = array_key_exists('status', $d) ? Validate::enum($d, 'status', self::STATUSES) : $cur['status']; if (!$st) throw new HttpError(422, Validate::MSG, ['status' => 'Invalid outcome']);
            $dur = array_key_exists('durationSeconds', $d) ? ($d['durationSeconds'] === null || $d['durationSeconds'] === '' ? null : (int)$d['durationSeconds']) : ($cur['duration_seconds'] === null ? null : (int)$cur['duration_seconds']);
            if ($dur !== null && ($dur < 0 || $dur > 86400)) throw new HttpError(422, Validate::MSG, ['durationSeconds' => 'Between 0 seconds and 24 hours']); if ($st !== 'connected' && $dur) throw new HttpError(422, Validate::MSG, ['durationSeconds' => 'Only connected calls have a duration']);
            $disp = array_key_exists('dispositionId', $d) ? self::disposition($d) : ($cur['disposition_id'] ? Db::one('SELECT * FROM call_dispositions WHERE id=?', [$cur['disposition_id']]) : null);
            $before = ['status' => $cur['status'], 'contactId' => $cur['contact_id'], 'tripId' => $cur['trip_id'], 'durationSeconds' => $cur['duration_seconds'], 'disposition' => $cur['disp_code']];
            Db::exec('UPDATE calls SET status=?, contact_id=?, trip_id=?, booking_id=?, duration_seconds=?, duration_source=?, disposition_id=?, disposition=?, updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?', [$st, $c, $t, $b, $dur, $dur === null ? 'unknown' : 'entered', $disp ? (int)$disp['id'] : null, $disp ? $disp['code'] : null, $cur['id']]);
            self::event((int)$cur['id'], (int)$u['id'], 'edited', $before, ['status' => $st, 'contactId' => $c, 'tripId' => $t, 'durationSeconds' => $dur, 'disposition' => $disp ? $disp['code'] : null]); Audit::log((int)$u['id'], 'call.edited', 'call', $cur['id']); });
        return self::get($pa);
    }
    public static function archive(array $pa): array { $u = Auth::require('calls.use'); $cur = self::find($u, (int)$pa['id']); self::canEdit($u, $cur); if ($cur['archived_at']) throw new HttpError(409, 'Already archived');
        Db::exec('UPDATE calls SET archived_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP(), version=version+1 WHERE id=?', [$cur['id']]); self::event((int)$cur['id'], (int)$u['id'], 'archived'); return self::get($pa); }
    public static function addNote(array $pa): array { $u = Auth::require('calls.use'); $c = self::find($u, (int)$pa['id']); $b = Validate::str(Http::body(), 'body', 5000, true);
        Db::exec('INSERT INTO call_notes (call_id, user_id, body, created_at) VALUES (?,?,?,UTC_TIMESTAMP())', [$c['id'], (int)$u['id'], $b]); self::event((int)$c['id'], (int)$u['id'], 'note_added'); self::timeline($c, (int)$u['id'], 'Call note added', 'call.note'); return self::get($pa); }
    public static function editNote(array $pa): array { $u = Auth::require('calls.use'); $n = Db::one('SELECT * FROM call_notes WHERE id=?', [(int)$pa['id']]); if (!$n) throw new HttpError(404, 'Note not found'); self::find($u, (int)$n['call_id']);
        if ((int)$n['user_id'] !== (int)$u['id'] && !Auth::can('calls.view_all')) throw new HttpError(403, 'Only the author or a supervisor can edit this note'); $b = Validate::str(Http::body(), 'body', 5000, true);
        Db::exec('UPDATE call_notes SET body=?, updated_at=UTC_TIMESTAMP() WHERE id=?', [$b, $n['id']]); self::event((int)$n['call_id'], (int)$u['id'], 'note_edited', ['body' => mb_substr($n['body'], 0, 500)], ['body' => mb_substr($b, 0, 500)]); return self::get(['id' => (int)$n['call_id']]); }
    /* ---- dispositions ---- */
    public static function dispositions(): array { Auth::require('calls.use'); return ['items' => array_map(function ($r) { return ['id' => (int)$r['id'], 'code' => $r['code'], 'label' => $r['label'], 'requiresFollowup' => (bool)$r['requires_followup'], 'isActive' => (bool)$r['is_active'], 'sortOrder' => (int)$r['sort_order']]; }, Db::all('SELECT * FROM call_dispositions ORDER BY sort_order, id'))]; }
    public static function saveDisposition(?array $pa = null): array { $u = Auth::require('calls.configure'); $d = Http::body(); $label = Validate::str($d, 'label', 80, true);
        if ($pa) { $cur = Db::one('SELECT * FROM call_dispositions WHERE id=?', [(int)$pa['id']]); if (!$cur) throw new HttpError(404, 'Outcome not found');
            Db::exec('UPDATE call_dispositions SET label=?, requires_followup=?, is_active=?, sort_order=?, updated_by=?, updated_at=UTC_TIMESTAMP() WHERE id=?', [$label, !empty($d['requiresFollowup']) ? 1 : 0, !isset($d['isActive']) || !empty($d['isActive']) ? 1 : 0, (int)($d['sortOrder'] ?? $cur['sort_order']), (int)$u['id'], $cur['id']]);
            Audit::log((int)$u['id'], 'call_disposition.updated', 'call_disposition', $cur['id'], ['from' => ['label' => $cur['label'], 'active' => (int)$cur['is_active']], 'to' => ['label' => $label, 'active' => !isset($d['isActive']) || !empty($d['isActive'])]]); }
        else { $code = preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace(' ', '_', $label))); if (Db::one('SELECT id FROM call_dispositions WHERE code=?', [$code])) throw new HttpError(409, 'An outcome with this name already exists');
            $id = Db::insert('INSERT INTO call_dispositions (code, label, requires_followup, is_active, sort_order, updated_by, updated_at) VALUES (?,?,?,1,?,?,UTC_TIMESTAMP())', [$code, $label, !empty($d['requiresFollowup']) ? 1 : 0, (int)($d['sortOrder'] ?? 99), (int)$u['id']]); Audit::log((int)$u['id'], 'call_disposition.created', 'call_disposition', $id, ['label' => $label]); }
        return self::dispositions(); }
    /* ---- phone lookup and contact numbers ---- */
    public static function lookup(): array { $u = Auth::require('calls.use'); $n = Phone::normalize((string)($_GET['number'] ?? ''), ($_GET['country'] ?? '') ?: null); $matches = [];
        if ($n['e164']) { [$cw, $cp] = ContactController::scope($u);   // only contacts this user may see; hidden matches are not revealed in any form
            $matches = array_map(function ($r) { return ['contactId' => (int)$r['id'], 'name' => trim($r['first_name'] . ' ' . $r['last_name']), 'type' => $r['contact_type'], 'label' => $r['label'], 'preferred' => (bool)$r['is_preferred']]; },
                Db::all("SELECT DISTINCT c.id, c.first_name, c.last_name, c.contact_type, cp.label, cp.is_preferred FROM contact_phones cp JOIN contacts c ON c.id=cp.contact_id AND c.deleted_at IS NULL WHERE cp.phone_e164=? AND $cw ORDER BY c.last_name LIMIT 10", array_merge([$n['e164']], $cp))); }
        return ['input' => (string)($_GET['number'] ?? ''), 'normalized' => $n, 'matches' => $matches, 'known' => (bool)$matches]; }
    public static function contactPhones(array $pa): array { $u = Auth::require('contacts.view'); $c = ContactController::find($u, (int)$pa['id']);
        return ['items' => array_map(function ($r) { $n = $r['phone_e164'] ? Phone::normalize($r['phone_raw']) : null; return ['id' => (int)$r['id'], 'label' => $r['label'], 'phone' => $r['phone_raw'], 'display' => $n ? $n['display'] : $r['phone_raw'], 'e164' => $r['phone_e164'], 'preferred' => (bool)$r['is_preferred'], 'source' => $r['source']]; }, Db::all('SELECT * FROM contact_phones WHERE contact_id=? ORDER BY is_preferred DESC, id', [$c['id']]))]; }
    public static function addPhone(array $pa): array { $u = Auth::require('contacts.edit'); $c = ContactController::find($u, (int)$pa['id']); $d = Http::body(); $raw = Validate::str($d, 'phone', 40, true); $n = Phone::normalize($raw, $d['country'] ?? null);
        if ($n['error']) throw new HttpError(422, Validate::MSG, ['phone' => $n['error']]); $label = Validate::enum($d, 'label', ['mobile', 'home', 'work', 'other'], 'mobile');
        if (Db::one('SELECT id FROM contact_phones WHERE contact_id=? AND phone_e164=?', [$c['id'], $n['e164']])) throw new HttpError(409, 'This contact already has this number');
        Db::tx(function () use ($c, $raw, $n, $label, $d, $u) { if (!empty($d['preferred'])) Db::exec('UPDATE contact_phones SET is_preferred=0 WHERE contact_id=?', [$c['id']]); $pref = !empty($d['preferred']) || !Db::one('SELECT id FROM contact_phones WHERE contact_id=? AND is_preferred=1', [$c['id']]);
            Db::exec("INSERT INTO contact_phones (contact_id, label, phone_raw, phone_e164, is_preferred, source, created_at) VALUES (?,?,?,?,?,'added',UTC_TIMESTAMP())", [$c['id'], $label, $raw, $n['e164'], $pref ? 1 : 0]); Audit::log((int)$u['id'], 'contact.phone_added', 'contact', $c['id']); });
        [$cw, $cp] = ContactController::scope($u); $dups = array_map(function ($r) { return ['contactId' => (int)$r['id'], 'name' => trim($r['first_name'] . ' ' . $r['last_name'])]; }, Db::all("SELECT DISTINCT c.id, c.first_name, c.last_name FROM contact_phones cp JOIN contacts c ON c.id=cp.contact_id AND c.deleted_at IS NULL WHERE cp.phone_e164=? AND c.id<>? AND $cw", array_merge([$n['e164'], $c['id']], $cp)));
        return self::contactPhones($pa) + ['alsoOn' => $dups]; }
    public static function deletePhone(array $pa): array { $u = Auth::require('contacts.edit'); $p = Db::one('SELECT * FROM contact_phones WHERE id=?', [(int)$pa['pid']]); if (!$p || (int)$p['contact_id'] !== (int)$pa['id']) throw new HttpError(404, 'Number not found'); ContactController::find($u, (int)$pa['id']);
        if ($p['source'] === 'contact_field') throw new HttpError(409, 'This is the contact’s main phone field; change it on the contact'); Db::exec('DELETE FROM contact_phones WHERE id=?', [$p['id']]); Audit::log((int)$u['id'], 'contact.phone_removed', 'contact', $pa['id']); return self::contactPhones($pa); }
    public static function backfillPhones(): array { $u = Auth::require('calls.configure'); $n = 0; foreach (Db::all("SELECT id, phone FROM contacts WHERE deleted_at IS NULL AND phone IS NOT NULL AND phone<>''") as $r) { Phone::syncContactField((int)$r['id'], $r['phone']); $n++; }
        Audit::log((int)$u['id'], 'contact.phones_backfilled', 'contact', null, ['count' => $n]); return ['synced' => $n]; }
    /* ---- reporting and export ---- */
    public static function report(): array { $u = Auth::require('calls.use'); [$w, $p] = self::filters($u); $J = "FROM calls cl LEFT JOIN users s ON s.id=cl.staff_id LEFT JOIN call_dispositions d ON d.id=cl.disposition_id WHERE $w";
        $grp = function ($col, $label) use ($J, $p) { return array_map(function ($r) use ($label) { return ['key' => $r['k'], 'label' => $label ? ($r['l'] ?? $r['k']) : $r['k'], 'count' => (int)$r['n']]; }, Db::all("SELECT $col k" . ($label ? ", $label l" : '') . ", COUNT(*) n $J GROUP BY k" . ($label ? ', l' : '') . ' ORDER BY n DESC', $p)); };
        $tot = Db::one("SELECT COUNT(*) n, SUM(cl.status<>'attempted' AND cl.status<>'cancelled') recorded, SUM(cl.status='attempted') open_handoffs, SUM(cl.duration_source='entered') with_duration, COALESCE(SUM(CASE WHEN cl.duration_source='entered' THEN cl.duration_seconds END),0) dur_sum $J", $p);
        $cbw = Auth::can('calls.view_all') ? '1=1' : 'cb.assigned_to=' . (int)$u['id'];
        $cb = Db::one("SELECT SUM(cb.status='completed') completed, SUM(cb.status='pending') pending, SUM(cb.status='pending' AND cb.scheduled_at < UTC_TIMESTAMP()) overdue FROM callbacks cb WHERE $cbw");
        $missedFollow = Db::one("SELECT COUNT(*) n, SUM(EXISTS (SELECT 1 FROM callbacks cb WHERE cb.call_id=cl.id) OR EXISTS (SELECT 1 FROM calls x WHERE x.contact_id=cl.contact_id AND x.direction='outbound' AND x.started_at>cl.started_at)) followed $J AND cl.status IN ('missed','no_answer') AND cl.direction='inbound'", $p);
        return ['note' => 'All figures come from staff-entered call logs and phone-app handoffs. None are verified by a phone network; durations are only those entered by staff.',
            'totals' => ['records' => (int)$tot['n'], 'outcomesRecorded' => (int)$tot['recorded'], 'handoffsAwaitingOutcome' => (int)$tot['open_handoffs'], 'enteredDurationCount' => (int)$tot['with_duration'], 'enteredDurationSeconds' => (int)$tot['dur_sum'],
                'averageEnteredDurationSeconds' => (int)$tot['with_duration'] ? (int)round((int)$tot['dur_sum'] / (int)$tot['with_duration']) : null, 'verifiedCalls' => 0],
            'byDirection' => $grp('cl.direction', null), 'byStatus' => $grp('cl.status', null), 'byStaff' => $grp('cl.staff_id', 's.full_name'), 'byDisposition' => $grp("COALESCE(d.code,'none')", "COALESCE(d.label,'No outcome')"), 'bySource' => $grp('cl.source', null),
            'byDate' => array_map(function ($r) { return ['date' => $r['dt'], 'count' => (int)$r['n']]; }, Db::all("SELECT DATE(cl.started_at) dt, COUNT(*) n $J GROUP BY dt ORDER BY dt", $p)),
            'callbacks' => ['completed' => (int)$cb['completed'], 'pending' => (int)$cb['pending'], 'overdue' => (int)$cb['overdue']], 'missedInbound' => ['total' => (int)$missedFollow['n'], 'followedUp' => (int)$missedFollow['followed']]]; }
    public static function export(): void { $u = Auth::require('calls.use'); [$w, $p] = self::filters($u);
        $rows = Db::all("SELECT cl.*, s.full_name staff_name, d.code disp_code, d.label disp_label FROM calls cl LEFT JOIN users s ON s.id=cl.staff_id LEFT JOIN call_dispositions d ON d.id=cl.disposition_id WHERE $w ORDER BY cl.started_at DESC LIMIT 5000", $p);
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="lti-calls-' . gmdate('Ymd') . '.csv"'); header('Cache-Control: no-store'); $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
        $cell = function ($v) { $v = (string)$v; return preg_match('/^[=+\-@]/', $v) && !preg_match('/^\+\d/', $v) ? "'" . $v : $v; };
        fputcsv($o, ['Started (' . Workflow::agencyTz()->getName() . ')', 'Direction', 'Outcome', 'Source', 'Verification', 'Phone', 'Contact', 'Trip', 'Staff', 'Duration (s, entered)', 'Disposition']);
        foreach ($rows as $r) { $x = self::out($u, $r); fputcsv($o, array_map($cell, [$x['startedAtLocal'], $x['direction'], $x['statusLabel'], $x['sourceLabel'], $x['verificationLabel'], $x['phoneDisplay'], $x['contact']['label'] ?? '', $x['trip']['label'] ?? '', $x['staffName'], $x['durationSeconds'], $x['disposition']['label'] ?? ''])); }
        fclose($o); Audit::log((int)$u['id'], 'call.exported', 'call', null, ['rows' => count($rows)]); exit; }
}

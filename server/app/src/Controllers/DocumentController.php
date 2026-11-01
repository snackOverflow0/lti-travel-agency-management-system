<?php
declare(strict_types=1);
namespace LTI\Controllers;
use LTI\{Auth, Audit, Db, Http, HttpError, Validate};
/** Files live outside the web root; MySQL holds metadata only. Every download goes through PHP permission checks. */
final class DocumentController {
    const ALLOWED = ['pdf' => ['application/pdf'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'gif' => ['image/gif'], 'webp' => ['image/webp'],
        'txt' => ['text/plain'], 'csv' => ['text/csv', 'text/plain', 'application/csv'], 'doc' => ['application/msword', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream'], 'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream']];
    const CATEGORIES = ['travel_documents', 'passports_ids', 'insurance', 'invoices_receipts', 'supplier', 'contracts', 'images', 'other'];
    private static function dir(): string { $d = $GLOBALS['LTI_CFG']['storage_dir'] ?? (LTI_APP . '/storage/documents'); if (!is_dir($d)) mkdir($d, 0750, true); return rtrim($d, '/\\'); }
    private static function maxBytes(): int { return (int)($GLOBALS['LTI_CFG']['upload_max_bytes'] ?? 10485760); }
    /** A user may access a document they uploaded, or one linked to a trip/contact/booking they may see; supplier documents need documents.view only. */
    private static function canAccess(array $u, array $d): bool {
        if ((int)$d['uploaded_by'] === (int)$u['id'] || Auth::can('settings.manage')) return true;
        try { if ($d['trip_id']) { TripController::findRow($u, (int)$d['trip_id']); return true; } } catch (HttpError $e) { }
        try { if ($d['contact_id']) { ContactController::find($u, (int)$d['contact_id']); return true; } } catch (HttpError $e) { }
        if ($d['booking_id']) { $b = Db::one('SELECT trip_id FROM bookings WHERE id=?', [$d['booking_id']]); try { if ($b) { TripController::findRow($u, (int)$b['trip_id']); return true; } } catch (HttpError $e) { } }
        return $d['supplier_id'] !== null && !$d['trip_id'] && !$d['contact_id'] && !$d['booking_id'];
    }
    private static function find(array $u, int $id, bool $includeArchived = false): array {
        $d = Db::one('SELECT d.*, u.full_name uploader FROM documents d LEFT JOIN users u ON u.id=d.uploaded_by WHERE d.id=?' . ($includeArchived ? '' : ' AND d.deleted_at IS NULL'), [$id]);
        if (!$d || !self::canAccess($u, $d)) throw new HttpError(404, 'Document not found');
        return $d;
    }
    private static function out(array $d): array {
        return ['id' => (int)$d['id'], 'name' => $d['title'] ?: $d['original_name'], 'originalName' => $d['original_name'], 'mimeType' => $d['mime_type'], 'size' => (int)$d['size_bytes'], 'category' => $d['category'], 'visibility' => $d['visibility'],
            'tripId' => $d['trip_id'] === null ? null : (int)$d['trip_id'], 'contactId' => $d['contact_id'] === null ? null : (int)$d['contact_id'], 'bookingId' => $d['booking_id'] === null ? null : (int)$d['booking_id'], 'supplierId' => $d['supplier_id'] === null ? null : (int)$d['supplier_id'],
            'uploadedBy' => $d['uploader'] ?? null, 'createdAt' => $d['created_at'], 'archivedAt' => $d['deleted_at'], 'previewable' => strpos($d['mime_type'], 'image/') === 0 || $d['mime_type'] === 'application/pdf'];
    }
    private static function links(array $u, array $src, ?array $cur): array {
        $l = [];
        foreach (['tripId' => 'trip_id', 'contactId' => 'contact_id', 'bookingId' => 'booking_id', 'supplierId' => 'supplier_id'] as $k => $col) {
            if (!array_key_exists($k, $src)) { $l[$col] = $cur ? $cur[$col] : null; continue; }
            $id = $src[$k] === null || $src[$k] === '' ? null : (int)$src[$k]; $l[$col] = $id; if ($id === null) continue;
            if ($col === 'trip_id') TripController::findRow($u, $id); elseif ($col === 'contact_id') ContactController::find($u, $id);
            elseif ($col === 'booking_id') { $b = Db::one('SELECT trip_id FROM bookings WHERE id=?', [$id]); if (!$b) throw new HttpError(422, Validate::MSG, ['bookingId' => 'Unknown booking']); TripController::findRow($u, (int)$b['trip_id']); }
            else { if (!Db::one('SELECT id FROM suppliers WHERE id=?', [$id])) throw new HttpError(422, Validate::MSG, ['supplierId' => 'Unknown supplier']); }
        }
        return $l;
    }
    public static function list(): array {
        $u = Auth::require('documents.view'); $w = []; $p = [];
        $w[] = !empty($_GET['archived']) ? 'd.deleted_at IS NOT NULL' : 'd.deleted_at IS NULL';
        foreach (['tripId' => 'd.trip_id', 'contactId' => 'd.contact_id', 'bookingId' => 'd.booking_id', 'supplierId' => 'd.supplier_id'] as $k => $col) if (($v = (int)($_GET[$k] ?? 0)) > 0) { $w[] = "$col=?"; $p[] = $v; }
        if (($c = (string)($_GET['category'] ?? '')) !== '') { Validate::enum(['c' => $c], 'c', self::CATEGORIES); $w[] = 'd.category=?'; $p[] = $c; }
        if (($q = trim((string)($_GET['q'] ?? ''))) !== '') { $like = '%' . addcslashes($q, '%_\\') . '%'; $w[] = '(d.title LIKE ? OR d.original_name LIKE ?)'; array_push($p, $like, $like); }
        $rows = Db::all('SELECT d.*, u.full_name uploader FROM documents d LEFT JOIN users u ON u.id=d.uploaded_by WHERE ' . implode(' AND ', $w) . ' ORDER BY d.id DESC LIMIT 500', $p);
        $items = []; foreach ($rows as $r) if (self::canAccess($u, $r)) $items[] = self::out($r);
        return ['items' => $items];
    }
    public static function get(array $pa): array { $u = Auth::require('documents.view'); return ['document' => self::out(self::find($u, (int)$pa['id']))]; }
    public static function upload(): array {
        $u = Auth::require('documents.manage');
        $f = $_FILES['file'] ?? null;
        if (!$f || !is_array($f) || is_array($f['name'])) throw new HttpError(422, 'Choose one file to upload');
        if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || ($f['size'] ?? 0) > self::maxBytes()) throw new HttpError(413, 'The file is larger than the ' . round(self::maxBytes() / 1048576) . ' MB limit');
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new HttpError(422, 'The upload did not complete. Please try again.');
        $orig = mb_substr(basename(str_replace('\\', '/', (string)$f['name'])), 0, 200); $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED[$ext])) throw new HttpError(415, 'File type not allowed. Allowed: ' . implode(', ', array_keys(self::ALLOWED)));
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: 'application/octet-stream';
        if (!in_array($mime, self::ALLOWED[$ext], true)) throw new HttpError(415, 'The file content does not match its extension');
        if ((int)$f['size'] <= 0) throw new HttpError(422, 'The file is empty');
        $src = $_POST; $links = self::links($u, $src, null);
        $cat = Validate::enum($src, 'category', self::CATEGORIES, 'other'); $vis = Validate::enum($src, 'visibility', ['internal', 'client'], 'internal');
        $stored = bin2hex(random_bytes(32)); $sub = gmdate('Y/m'); $dir = self::dir() . '/' . $sub; if (!is_dir($dir)) mkdir($dir, 0750, true);
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $stored)) throw new HttpError(500, 'Could not store the file');
        $id = Db::insert('INSERT INTO documents (original_name, title, stored_name, mime_type, size_bytes, sha256, category, visibility, trip_id, contact_id, booking_id, supplier_id, uploaded_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())',
            [$orig, Validate::str($src, 'title', 255) ?: $orig, $sub . '/' . $stored, $mime, (int)$f['size'], hash_file('sha256', $dir . '/' . $stored), $cat, $vis, $links['trip_id'], $links['contact_id'], $links['booking_id'], $links['supplier_id'], (int)$u['id']]);
        Audit::log((int)$u['id'], 'document.uploaded', 'document', $id, ['name' => $orig, 'size' => (int)$f['size']]);
        return ['document' => self::out(self::find($u, $id))];
    }
    public static function update(array $pa): array {
        $u = Auth::require('documents.manage'); $cur = self::find($u, (int)$pa['id']); $d = Http::body(); $links = self::links($u, $d, $cur);
        $title = array_key_exists('name', $d) ? (Validate::str($d, 'name', 255, true)) : $cur['title'];
        Db::exec('UPDATE documents SET title=?, category=?, visibility=?, trip_id=?, contact_id=?, booking_id=?, supplier_id=?, updated_at=UTC_TIMESTAMP() WHERE id=?',
            [$title, Validate::enum($d, 'category', self::CATEGORIES, $cur['category']), Validate::enum($d, 'visibility', ['internal', 'client'], $cur['visibility']), $links['trip_id'], $links['contact_id'], $links['booking_id'], $links['supplier_id'], $cur['id']]);
        Audit::log((int)$u['id'], 'document.updated', 'document', $cur['id'], ['name' => $title]);
        return ['document' => self::out(self::find($u, (int)$cur['id']))];
    }
    public static function archive(array $pa): array {
        $u = Auth::require('documents.manage'); $cur = self::find($u, (int)$pa['id']);
        Db::exec('UPDATE documents SET deleted_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP() WHERE id=?', [$cur['id']]);   // file is retained for audit/recovery
        Audit::log((int)$u['id'], 'document.archived', 'document', $cur['id']);
        return ['ok' => true];
    }
    private static function stream(array $d, bool $inline): void {
        $path = self::dir() . '/' . $d['stored_name'];
        if (!preg_match('#^\d{4}/\d{2}/[a-f0-9]{64}$#', $d['stored_name']) || !is_file($path)) throw new HttpError(404, 'File not found on the server');
        $okInline = $inline && (strpos($d['mime_type'], 'image/') === 0 || $d['mime_type'] === 'application/pdf');
        $name = preg_replace('/[^\w.\- ]+/', '_', $d['title'] ?: $d['original_name']);
        header('Content-Type: ' . ($okInline ? $d['mime_type'] : 'application/octet-stream')); header('Content-Length: ' . filesize($path));
        header('Content-Disposition: ' . ($okInline ? 'inline' : 'attachment') . '; filename="' . $name . '"'); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        readfile($path); exit;
    }
    public static function download(array $pa): void { $u = Auth::require('documents.view'); $d = self::find($u, (int)$pa['id']); Audit::log((int)$u['id'], 'document.downloaded', 'document', $d['id']); self::stream($d, !empty($_GET['inline'])); }
    /** Travelers download client-visible documents linked to their published trips. */
    public static function portalDownload(array $pa): void {
        $u = Auth::require('portal.access'); $c = Db::one('SELECT id FROM contacts WHERE portal_user_id=? AND deleted_at IS NULL', [$u['id']]); if (!$c) throw new HttpError(404, 'Document not found');
        $d = Db::one("SELECT d.* FROM documents d JOIN trips t ON t.id=d.trip_id JOIN trip_travelers tt ON tt.trip_id=t.id AND tt.contact_id=? WHERE d.id=? AND d.deleted_at IS NULL AND d.visibility='client' AND t.is_published=1 AND t.archived_at IS NULL", [$c['id'], (int)$pa['id']]);
        if (!$d) throw new HttpError(404, 'Document not found');
        Audit::log((int)$u['id'], 'document.portal_downloaded', 'document', $d['id']); self::stream($d, !empty($_GET['inline']));
    }
}

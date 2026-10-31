<?php
declare(strict_types=1);
// Single API entry point. Application code lives OUTSIDE the web root (see DEPLOYMENT guide); LTI_APP_DIR overrides the default.
$appDir = getenv('LTI_APP_DIR') ?: dirname(__DIR__, 2) . '/app';
require $appDir . '/bootstrap.php';
use LTI\{Http, HttpError, Router, Session};
use LTI\Controllers\{AuthController, ContactController, ItineraryController, PortalController, StaffController, TripController};
$dev = ($GLOBALS['LTI_CFG']['env'] ?? 'production') === 'development';
try {
    Session::start();
    $method = $_SERVER['REQUEST_METHOD'];
    $path = '/' . trim((string)($_GET['r'] ?? preg_replace('#^.*?/api#', '', (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))), '/');
    if (!in_array($method, ['GET', 'HEAD'], true) && !in_array($path, ['/auth/login', '/auth/reset'], true)) Session::checkCsrf();
    $r = new Router();
    $r->add('POST', '/auth/login', function () { return AuthController::login(); });
    $r->add('POST', '/auth/logout', function () { return AuthController::logout(); });
    $r->add('GET', '/auth/me', function () { return AuthController::me(); });
    $r->add('POST', '/auth/reset', function () { return AuthController::resetPassword(); });
    $r->add('GET', '/staff', function () { return StaffController::list(); });
    $r->add('PATCH', '/staff/{id}', function ($p) { return StaffController::setActive($p); });
    $r->add('POST', '/staff/{id}/reset-link', function ($p) { return AuthController::createResetLink($p); });
    $r->add('GET', '/contacts', function () { return ContactController::list(); });
    $r->add('POST', '/contacts', function () { return ContactController::create(); });
    $r->add('GET', '/contacts/staff-options', function () { return ContactController::staffOptions(); });
    $r->add('GET', '/contacts/{id}', function ($p) { return ContactController::get($p); });
    $r->add('PUT', '/contacts/{id}', function ($p) { return ContactController::update($p); });
    $r->add('DELETE', '/contacts/{id}', function ($p) { return ContactController::delete($p); });
    $r->add('POST', '/contacts/{id}/portal-account', function ($p) { return PortalController::createAccount($p); });
    $r->add('GET', '/trips', function () { return TripController::list(); });
    $r->add('POST', '/trips', function () { return TripController::create(); });
    $r->add('GET', '/trips/{id}', function ($p) { return TripController::get($p); });
    $r->add('PUT', '/trips/{id}', function ($p) { return TripController::update($p); });
    $r->add('POST', '/trips/{id}/archive', function ($p) { return TripController::archive($p); });
    $r->add('POST', '/trips/{id}/restore', function ($p) { return TripController::archive($p, true); });
    $r->add('PUT', '/trips/{id}/travelers', function ($p) { return TripController::setTravelers($p); });
    $r->add('GET', '/trips/{id}/itinerary', function ($p) { return ItineraryController::get($p); });
    $r->add('PUT', '/trips/{id}/itinerary', function ($p) { return ItineraryController::save($p); });
    $r->add('POST', '/trips/{id}/publish', function ($p) { return ItineraryController::publish($p); });
    $r->add('POST', '/trips/{id}/unpublish', function ($p) { return ItineraryController::unpublish($p); });
    $r->add('GET', '/trips/{id}/versions/{vid}', function ($p) { return ItineraryController::version($p); });
    $r->add('POST', '/trips/{id}/comments', function ($p) { return ItineraryController::staffComment($p); });
    $r->add('GET', '/portal/trips', function () { return PortalController::list(); });
    $r->add('GET', '/portal/trips/{id}', function ($p) { return PortalController::get($p); });
    $r->add('POST', '/portal/trips/{id}/select', function ($p) { return PortalController::select($p); });
    $r->add('POST', '/portal/trips/{id}/approve', function ($p) { return PortalController::approve($p); });
    $r->add('POST', '/portal/trips/{id}/request-changes', function ($p) { return PortalController::requestChanges($p); });
    $r->add('POST', '/portal/trips/{id}/comments', function ($p) { return PortalController::comment($p); });
    $r->add('GET', '/health', function () { return ['ok' => true]; });
    $out = $r->dispatch($method, $path);
    Http::json($out, ($method === 'POST' && in_array($path, ['/contacts', '/trips'], true)) ? 201 : 200);
} catch (HttpError $e) {
    Http::json(['error' => $e->getMessage(), 'fields' => (object)$e->fields], $e->getCode());
} catch (Throwable $e) {
    error_log('[LTI] ' . $e);
    Http::json(['error' => $dev ? $e->getMessage() : 'Unexpected server error'], 500);
}

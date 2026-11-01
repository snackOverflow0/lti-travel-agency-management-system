<?php
declare(strict_types=1);
namespace LTI;
require_once LTI_APP . '/vendor/phpmailer/Exception.php'; require_once LTI_APP . '/vendor/phpmailer/PHPMailer.php'; require_once LTI_APP . '/vendor/phpmailer/SMTP.php';
use PHPMailer\PHPMailer\PHPMailer;
/** SMTP transport (PHPMailer, LGPL). "accepted" means the configured SMTP server accepted the message (250 after DATA);
 *  it is not proof of delivery or inbox placement. Credentials come only from config.php outside the repository. */
final class Mailer {
    public static function configured(): bool { $c = $GLOBALS['LTI_CFG']['mail'] ?? []; return ($c['transport'] ?? 'none') === 'smtp' && !empty($c['host']) && !empty($c['from_email']); }
    public static function status(): array { $c = $GLOBALS['LTI_CFG']['mail'] ?? []; return ['configured' => self::configured(), 'transport' => self::configured() ? 'smtp' : 'none', 'host' => self::configured() ? $c['host'] : null, 'fromEmail' => $c['from_email'] ?? null, 'maxPerHour' => (int)($c['max_per_hour'] ?? 100)]; }
    /** @return array{result:string, response:string, messageId:?string} result: accepted | failed | unknown */
    public static function send(array $email): array {
        $c = $GLOBALS['LTI_CFG']['mail'];
        foreach (explode(',', (string)$email['to_addr']) as $to) if (!filter_var(trim($to), FILTER_VALIDATE_EMAIL)) return ['result' => 'failed', 'response' => 'Invalid recipient address', 'messageId' => null];
        $m = new PHPMailer(true); $m->isSMTP(); $m->Host = $c['host']; $m->Port = (int)($c['port'] ?? 587); $m->Timeout = (int)($c['timeout'] ?? 20); $m->CharSet = 'UTF-8';
        $enc = $c['encryption'] ?? 'tls'; $m->SMTPSecure = $enc === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : ($enc === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : ''); $m->SMTPAutoTLS = $enc !== 'none';
        if (!empty($c['username'])) { $m->SMTPAuth = true; $m->Username = $c['username']; $m->Password = (string)($c['password'] ?? ''); }
        try {
            $m->setFrom($c['from_email'], $c['from_name'] ?? 'Luxury Travel Institute', false);
            foreach (explode(',', (string)$email['to_addr']) as $to) $m->addAddress(trim($to));
            $m->Subject = str_replace(["\r", "\n"], ' ', (string)$email['subject']);   // header injection guard
            $m->isHTML(true); $m->Body = Template::html((string)$email['body']); $m->AltBody = (string)$email['body'];
            $m->MessageID = sprintf('<lti-%d-%s@%s>', $email['id'], bin2hex(random_bytes(6)), preg_replace('/^.*@/', '', $c['from_email']));
            $m->send();
            $reply = $m->getSMTPInstance() ? trim((string)$m->getSMTPInstance()->getLastReply()) : '';
            return ['result' => 'accepted', 'response' => Jobs::redact('Accepted by SMTP server: ' . mb_substr($reply, 0, 150)), 'messageId' => $m->MessageID];
        } catch (\Throwable $e) {
            $msg = Jobs::redact($e->getMessage());
            // Failures before the message data was handed over are definite (safe to retry); anything else is uncertain.
            $definite = preg_match('/connect\(\) failed|Could not connect|authenticate|Invalid address|data not accepted|recipients failed|from address failed|Called (MAIL|RCPT)/i', $msg);
            return ['result' => $definite ? 'failed' : 'unknown', 'response' => $msg, 'messageId' => null];
        }
    }
}

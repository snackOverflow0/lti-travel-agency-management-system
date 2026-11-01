# Email configuration

Add to `config.php` (outside the web root; never commit it):
```php
'mail' => ['transport' => 'smtp', 'host' => 'mail.YOUR-DOMAIN', 'port' => 587, 'encryption' => 'tls',
           'username' => 'advisors@YOUR-DOMAIN', 'password' => '…', 'from_email' => 'advisors@YOUR-DOMAIN',
           'from_name' => 'Luxury Travel Institute', 'max_per_hour' => 100, 'timeout' => 20],
```
Use the mailbox and SMTP details shown in the Network Solutions control panel (SMTP availability and sending limits are **unverified**; set `max_per_hour` at or below the host's limit). With `'transport' => 'none'` (the default) queued messages become **Failed** with an explanation — nothing is pretended to be sent.

**Statuses.** Draft → Queued → Sending → *Accepted by mail server* (the SMTP server returned success; not proof of delivery or inbox placement) or *Failed* (definite refusal; retried up to 3 times when the failure happened before the message was handed over) or *Unknown — needs review* (outcome unclear, e.g. a worker stopped mid-send; never resent automatically; staff record the real outcome). Delivered/Bounced are not shown because no delivery evidence is collected.

**Deliverability (with your domain/DNS provider).** Publish SPF including the host's mail servers, enable DKIM signing if the host offers it, and add a DMARC record (start with `p=none` and a reporting address). Inbox placement is never guaranteed.

**Safety built in.** Recipient validation, subject/header injection blocking, HTML produced only by escaping plain text, allowlisted template variables, hourly rate limit, at-most-once sending per message, credentials redacted from every log and API response (tested).

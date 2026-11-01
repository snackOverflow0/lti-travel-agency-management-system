# Local testing (XAMPP) and Network Solutions compatibility

## XAMPP
1. Back up the database; import `server/app/migrations/008_phase7.sql` (phpMyAdmin) or run `C:\xampp\php\php.exe server\app\bin\migrate.php`.
2. Copy the ZIP over the project (keep your own `config.php`); start `php -S 127.0.0.1:8000 -t server\public server\dev-router.php`.
3. Sign in as an owner/admin, open **Calls › Settings** and click **Sync contact phone numbers** once.
4. Open the phone panel (phone button, bottom right): type a contact’s number, use **Open phone app** (on Windows this opens the app registered for `tel:` links, e.g. Phone Link or a softphone, if any) and then **Record outcome**; or use **Log a call**.
5. Manual check needed for TC06: confirm your phone app opens with the correct number.

## Network Solutions
No telephony component is required on the server: Phase 7 is plain PHP/MySQL. `tel:` handoff happens on the user’s device. Callback reminders depend on the Phase 6 scheduler, whose availability on the hosting plan is still **unverified (BLOCKED)**; without it, callbacks and their tasks still work but reminder notifications are not delivered automatically.

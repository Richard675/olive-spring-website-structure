OLIVE SPRIGS HEALTH SOLUTIONS — WEBSITE + BACKEND (2026)

This folder is the complete site: the public website (frontend), the form API and database,
and a private admin dashboard where your team manages care requests and job applications.
Plain PHP + SQLite. Nothing to install with npm or Composer; it runs on ordinary shared hosting.


01. WHAT'S INSIDE

Public website
  index.html, about.html, services.html, service-areas.html, resources.html, contact.html, 404.html
  careers.html        Career information and the online job application (with optional résumé upload)
  style.css           Design system: colors, type, components, responsive rules
  script.js           Mobile menu, scroll animations, service jump bar, both website forms
  assets/             Self-hosted fonts (SIL Open Font License), logo, photography

Form API (public endpoints)
  api/contact.php     Care request form  -> saved to the database, emailed to the office
  api/careers.php     Job application    -> saved to the database, résumé stored privately, emailed to the office

Admin dashboard (private, sign-in required)
  admin/              Visit https://your-domain/admin/
  admin/index.php     Inbox: counts, search, status filter, pagination, CSV download
  admin/view.php      One record: call/email buttons, status, internal notes, history, résumé download, delete
  admin/account.php   Change your password; add or remove team members
  admin/setup.php     One-time creation of the first admin account (disabled afterwards)

Backend code and data (never served to the public)
  app/config.php      All settings (email, SMTP, limits, time zone)
  app/bootstrap.php   Loads settings, database, security helpers
  app/lib/            data.php (services, counties, positions, statuses), db.php (database + migrations),
                      security.php (sessions, CSRF, rate limits), mailer.php (mail/SMTP), forms.php, admin.php
  app/cli.php         Command-line tools (create admin, reset password, cleanup, test email)
  app/dev-router.php  Local development server
  storage/            Created automatically: database.sqlite, uploads/ (résumés), logs/, app.key
  .htaccess           Apache rules: blocks app/ and storage/, adds security headers
  .user.ini           PHP upload limits for PHP-FPM / cPanel hosts


02. HOSTING REQUIREMENTS
  PHP 8.1 or newer with pdo_sqlite, fileinfo, mbstring (all standard on cPanel/Plesk hosts). zip is recommended.
  HTTPS (free with Let's Encrypt on most hosts). The admin cookie is marked Secure on HTTPS.
  The web server user must be able to write to storage/.
  Static-only hosting (GitHub Pages, Netlify static) cannot run PHP.


03. GO-LIVE STEPS (about 15 minutes)
  1. Upload the whole folder to your web root (public_html), including the hidden .htaccess and .user.ini files.
  2. Make storage/ writable by the web server (cPanel File Manager: permissions 755 or 775 on storage/).
  3. Open app/config.php and set:
       notify_to / careers_to   where new care requests and applications are emailed
       mail_from                a sender on your own domain, e.g. website@olivesprigshealthsolutions.com
       mail_driver              "smtp" recommended (see section 04), or "mail" for PHP's built-in mail
       site_url                 https://www.olivesprigshealthsolutions.com  (used for links in notification emails)
  4. Visit https://your-domain/admin/  You will be sent to the setup page.
  5. Open storage/setup-key.txt in your hosting File Manager, copy the key, and paste it into the setup page
     with your name, email and a password (12+ characters). The key file deletes itself once the account exists.
     (With SSH you can instead run: php app/cli.php create-admin "Your Name" you@example.com)
  6. Submit a test care request on contact.html and a test application on careers.html.
     Both should appear in the dashboard and arrive by email.
  7. Check that https://your-domain/storage/database.sqlite and https://your-domain/app/config.php
     both show "not found". If they load, your host is ignoring .htaccess; see section 07.


04. EMAIL
  Every submission is saved to the database first, so nothing is lost if email fails. The dashboard shows
  a notice if any recent submission could not be emailed.

  SMTP (recommended, best deliverability). In app/config.php set mail_driver to "smtp" and fill in smtp:
    Google Workspace: host smtp.gmail.com, port 587, encryption tls, username = full address, password = app password
    Microsoft 365:    host smtp.office365.com, port 587, encryption tls
    cPanel mailbox:   host mail.your-domain.com, port 465, encryption ssl
  Test it over SSH with:  php app/cli.php test-email

  Visitor confirmation emails ("we received your request") are off by default. Turn on send_confirmation
  only after SMTP works, because it sends mail to whatever address a visitor types.

  Any setting can also be set as an environment variable instead of editing the file:
  OS_NOTIFY_TO, OS_CAREERS_TO, OS_MAIL_FROM, OS_MAIL_FROM_NAME, OS_MAIL_DRIVER, OS_SMTP_HOST, OS_SMTP_PORT,
  OS_SMTP_ENCRYPTION, OS_SMTP_USERNAME, OS_SMTP_PASSWORD, OS_SEND_CONFIRMATION, OS_SITE_URL, OS_TIMEZONE,
  OS_RETENTION_DAYS, OS_STORAGE_PATH.


05. USING THE DASHBOARD
  Care request statuses:  New -> Contacted -> Assessment scheduled -> Active client -> Closed
  Application statuses:   New -> Reviewing -> Interview -> Hired / Not selected
  Internal notes and status changes are recorded in each record's history with who made them and when.
  "Download CSV" exports exactly what the inbox is showing (current search and status filter); it opens in Excel.
  Team & account: add a colleague with a temporary password and ask them to change it after signing in.
  Removing someone signs them out immediately. You cannot remove your own account.
  Forgot your password? Another admin can add a new account for you, or run:
    php app/cli.php reset-password you@example.com
  Sessions end after 30 minutes without activity.


06. SECURITY BUILT IN
  Forms: server-side validation of every field, allow-lists for services/counties/positions, same-origin check,
  hidden bot trap, rate limit (5 submissions per 10 minutes per visitor), length limits, no email header injection.
  Résumés: PDF/DOC/DOCX only, 5 MB max, checked by real file content (not just the name), stored under a
  random name outside the public folders, downloadable only by signed-in staff, always as a download.
  Admin: passwords hashed with PHP password_hash; sign-in throttled per account and per address;
  CSRF tokens on every action; HttpOnly + SameSite=Strict session cookie; strict Content-Security-Policy;
  pages marked noindex and never cached; setup page needs a one-time key and disables itself.
  Privacy: visitor IP addresses are never stored, only a keyed hash for rate limiting. Logs never contain form content.
  CSV export neutralises spreadsheet formulas typed into forms.

  Data retention: set retention_days in app/config.php (e.g. 730) and schedule a daily cron job:
    php /path/to/site/app/cli.php cleanup
  This deletes older records and their résumés. With 0 (the default), nothing is deleted automatically.

  Backups: download storage/ (the database and résumés) regularly. It contains personal information,
  so store backups securely. Care requests may contain health details; do not ask visitors for medical records
  through the website, and review whether your agency needs a HIPAA-covered email or hosting provider.


07. NGINX OR HOSTS THAT IGNORE .htaccess
  Nginx: add these to your server block (adjust the PHP socket for your host):
    location ~ ^/(app|storage)/ { return 404; }
    location ~ /\. { deny all; }
    location ~ \.(sqlite|log|key|md|py)$ { return 404; }
    location /admin/ { index index.php; }
    client_max_body_size 8m;
    location ~ \.php$ { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; fastcgi_pass unix:/run/php/php8.3-fpm.sock; }
  Safest option on any host: move storage/ outside the web root and set OS_STORAGE_PATH (or storage_path in
  app/config.php) to its new location.


08. LOCAL DEVELOPMENT
  From this folder run:
    php -d upload_max_filesize=6M -S localhost:8000 app/dev-router.php
  Open http://localhost:8000 for the site and http://localhost:8000/admin/ for the dashboard.
  To avoid sending real email while testing, set mail_driver to "log"; messages are written to storage/logs/mail.log.
  The database schema upgrades itself (app/lib/db.php, PRAGMA user_version). To change services, counties or
  positions, edit app/lib/data.php and the matching options on contact.html / careers.html.
  Linking to a pre-selected service: contact.html?service=dementia | post-surgical | assisted-living |
  private-duty-nursing | chronic-illness | disability | adult-care-home | non-medical | pediatric


09. CONTENT NOTES (from the redesign)
  The nine services supplied on 2026-10-03 replace the previous list everywhere. Copy edits: punctuation and spacing;
  "Post-Surgical Patients" -> "Post-Surgical Care"; "Pediatric Patients" -> "Pediatric Care";
  "Non-Medical Care Services" -> "Non-Medical Home Care". The supplied Non-Medical text was a copy of
  Disability Care, so it was rewritten (companionship, personal care, housekeeping, meals, errands, respite).
  Please review it, and confirm every service is covered by the agency's North Carolina licensure.


10. CHECKS COMPLETED (Chromium, desktop 1440px and phone 390px, PHP 8.3)
  76 automated end-to-end checks passed, including: both forms with and without JavaScript; invalid and oversized
  uploads; a PHP script disguised as a PDF; cross-site posts; bot trap; rate limits; first-run setup; sign-in,
  throttling and deep links; status, notes, delete; CSV export and formula escaping; résumé download;
  forged requests without CSRF tokens; private files and folders unreachable; every admin page blocked when signed out.
  axe-core accessibility scan (WCAG 2.1 AA + best practice): no violations on any public or admin page.
  A live email test on your own host is still required (step 6 above).

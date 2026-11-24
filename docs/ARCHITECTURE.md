# HealthBridge — Architecture

How the final V2 build is put together. For setup instructions see the
[README](../README.md).

## Overview

HealthBridge is a server-rendered-by-API PHP application with a static
HTML/vanilla-JS frontend. There is no framework and no build step:

- HTML pages (public site + three role dashboards) load per-page JavaScript
  controllers.
- Controllers call JSON endpoints under `api/` (grouped by domain: `auth`,
  `appointments`, `doctors`, `medical`, `notifications`, `prescriptions`,
  `patient`, `patients`, `departments`, `schedule`, `settings`, `admin`,
  `audit`).
- Endpoints are thin: they authenticate/authorize via `includes/`, validate
  input, delegate to OOP services in `services/` (with `services/Repositories/`
  for data access), and return a standard JSON shape via `includes/response.php`.
- All database access is PDO with prepared statements against MariaDB
  (`includes/db.php`, configured from `.env` via phpdotenv when Composer's
  autoloader is present).

Authentication is PHP-session based with role guards (patient / doctor /
admin). Session IDs rotate at login; protected endpoints recheck account
status and role, and resource-level ownership checks scope patients and
doctors to their own records (with one deliberate exception: doctors may
view/print any patient's prescriptions read-only, while edit/complete/cancel
stays with the prescribing doctor).

## Public V2 frontend

The current frontend is a standalone asset layer in `assets/public-v2/` that
loads nothing from the retired V1 frontend:

- `css/tokens.css` — shared design tokens; `css/base.css`, `css/components.css`,
  `css/footer.css` — shared primitives; `css/pages/<page>.css` — page-only styles.
- `js/core/` — shared shell code: site navigation/theme/toasts, the footer
  loader, and the dashboard shells (`shell-core.js`, `admin-shell.js`).
- `js/pages/` — one controller per page.
- The public footer is a single canonical fragment
  (`includes/fragments/footer.html`) fetched at runtime by `js/core/footer.js`
  on every public page, so there is exactly one footer implementation.
- Theming: dark is the default (`<html>` with no `data-theme`); light is
  `html[data-theme="light"]`.

Public pages live at the repository root (`index.html`, `about.html`,
`doctors.html`, `services.html`, `contact.html`); auth pages and dashboards
live under `pages/auth|patient|doctor|admin/`.

## The three dashboards

Each role gets its own shell and page set, all built as new V2 files:

- **Patient** (`pages/patient/`): dashboard home, appointments (list + booking
  wizard), prescriptions, medical history, notifications, downloads, profile,
  settings. Data comes mainly from `api/patient/*` and domain endpoints.
- **Doctor** (`pages/doctor/`): dashboard, patient queue, appointments,
  clinical workspace (visit drafts, encounter workflow, EMR), schedule editor,
  reviews, notifications, profile, settings. The schedule editor and Settings
  share one server-side consultation-duration value.
- **Admin** (`pages/admin/`): overview with hand-built charts (no chart
  library), doctors (+ a consolidated doctor-profile page with review
  moderation), patients (+ patient profile), departments, appointments
  (approve/decline), a read-only prescriptions audit view, support messages,
  an audit-log viewer, and settings/system settings. Support replies are
  delivered in-app to registered users; guest messages are labelled
  undeliverable rather than emailed.

Dashboards share the notification bell behaviour: opening the pop-up does not
mark anything read; explicit actions mark all read or dismiss items from the
pop-up only, while the Notification Center retains everything.

## Registration: intake queue + worker

Registration is deliberately asynchronous — submitting the form does not
create an account:

1. `api/auth/register.php` validates the two-step form. At the final step it
   refuses the submission immediately (HTTP 409, specific message) if the
   email, phone number or national ID already belongs to an account.
2. Otherwise the password is hashed and the whole profile is encrypted
   (AES-256-GCM, keys supplied via `HB_REGISTRATION_QUEUE_KEYS_JSON` /
   `HB_REGISTRATION_QUEUE_ACTIVE_KEY_VERSION`) and inserted as one row in
   `registration_intake_queue`. No plaintext PII or password hash is stored
   in queue columns.
3. The CLI worker `tools/process_registration_intake.php` claims pending rows
   (leased, retried up to a bounded number of attempts), decrypts the profile,
   re-checks identity conflicts as a backstop, and either marks the row
   `duplicate` or creates a verification challenge
   (`registration_email_challenges`) and sends the confirmation email through
   `HealthBridgeMailService` (Gmail SMTP; sender configured by environment).
4. The emailed link (valid 24 hours) hits
   `api/auth/confirm-registration-email.php`; only then does the worker-side
   flow create the user, patient profile, audit and notification rows in one
   transaction.

Registration and login are rate-limited by source IP and by HMACs of the
submitted identifiers (`includes/AuthAbuseRateLimiter.php`); the limiter
fails soft if its storage is unavailable.

## Notification outbox worker

Business transactions never send notifications directly. Producers enqueue
rows into `notification_outbox` inside the same database transaction as the
change that caused them (appointment lifecycle and reschedule transitions,
prescription changes, visit completion, account lifecycle, ratings, support
replies, and more). The CLI worker `tools/process_notification_outbox.php`
delivers pending rows into the in-app notification system, with bounded
retries; delivered/skipped rows are pruned by a 30-day retention pass.

## Deployment (live demo)

- **Host:** alwaysdata free plan — one site, PHP 8.4, MariaDB 11.8.
- **Code:** the repository tree served from the site root; `/.env` is not
  web-accessible (verified 403). The root `.env` file holds only the two
  registration-queue key variables; database and mail settings are provided
  as site environment variables (`DB_*`, `APP_BASE_URL`, `SMTP_PASS`).
- **Workers:** one scheduled task runs both workers every minute:

  ```bash
  php tools/process_registration_intake.php --limit=50 && php tools/process_notification_outbox.php --limit=50
  ```

  Note: on alwaysdata, a scheduled task carries its **own** environment
  variables, separate from the site's — both must be kept in sync.
- Force HTTPS is enabled; the canonical origin is
  `https://youssef1.alwaysdata.net`.

## Data

All data is fictional demo data generated for this portfolio project
(45 doctors across 9 departments, 500 patients, and matching appointments,
prescriptions, ratings and notifications), plus three demo accounts
documented in the README.

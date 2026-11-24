# HealthBridge — Appointment Rescheduling Workflow
## Complete Implementation Documentation

---

## Table of Contents
1. [Files Modified](#files-modified)
2. [Database Changes](#database-changes)
3. [API Changes](#api-changes)
4. [Service Changes](#service-changes)
5. [Notification Changes](#notification-changes)
6. [Audit Changes](#audit-changes)
7. [UI Changes](#ui-changes)
8. [Complete Workflow Diagram](#workflow-diagram)
9. [Manual Test Checklist](#manual-test-checklist)

---

## Files Modified

### New Files Created
| File | Purpose |
|------|---------|
| `services/RescheduleService.php` | Core business logic for reschedule workflow |
| `api/appointments/request-reschedule.php` | POST endpoint for patient reschedule requests |
| `api/appointments/approve-reschedule.php` | POST endpoint for doctor approval |
| `api/appointments/reject-reschedule.php` | POST endpoint for doctor rejection |
| `api/appointments/get-pending-reschedules.php` | GET endpoint for pending reschedule requests |
| `database/migrations/phase10_reschedule_workflow.sql` | Database migration script |

### Modified Files
| File | Changes |
|------|---------|
| `services/NotificationService.php` | Added 4 new notification type constants for reschedule events |
| `services/ScheduleService.php` | Made `getBookedRanges()` public; added `'Reschedule Requested'` to status filter in booked ranges |
| `assets/js/components/booking-wizard.js` | Added `rescheduleMode`, `openReschedule()`, `lockRescheduleFields()`, `updateRescheduleUI()`, `restoreBookingUI()`; modified `close()` and `handleFormSubmit()` for reschedule flow |
| `assets/js/pages/patient/appointments.js` | Updated status display for "Reschedule Requested"; updated `reschedule()` method to use `openReschedule()` |
| `pages/patient/appointments.html` | Added "Rescheduled" filter chip |
| `database/database.sql` | (Recommended) Update ENUM to include 'Reschedule Requested' |

---

## Database Changes

### New Columns on `appointments` Table

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `pending_reschedule_date` | DATE | NULL | Current pending/suggested target date |
| `pending_reschedule_time` | VARCHAR(20) | NULL | Current pending/suggested target time |
| `requested_reschedule_date` | DATE | NULL | Original date requested by the patient; retained if a doctor suggests an alternative |
| `requested_reschedule_time` | VARCHAR(20) | NULL | Original time requested by the patient; retained if a doctor suggests an alternative |
| `reschedule_reason` | TEXT | NULL | Patient reason for rescheduling |
| `reschedule_requested_at` | DATETIME | NULL | When the reschedule was requested |
| `reschedule_requested_by` | INT | NULL | User ID who requested the reschedule |
| `reschedule_status` | ENUM('none','pending','suggested','approved','rejected') | 'none' | Current reschedule workflow status |
| `reschedule_responded_at` | DATETIME | NULL | When doctor approved/rejected |
| `reschedule_responded_by` | INT | NULL | Doctor user ID who responded |
| `reschedule_response_notes` | TEXT | NULL | Doctor notes on approval/rejection |

### Modified ENUM on `appointments.status`
- Added: `'Reschedule Requested'`
- Current valid values: `'Pending', 'Confirmed', 'Cancelled', 'Reschedule Requested'`
- `reschedule_status` also includes `'suggested'` to represent a doctor-proposed alternative awaiting patient response.

### New Indexes
- `idx_appt_reschedule_status` ON `appointments(reschedule_status)`
- `idx_appt_reschedule_requested` ON `appointments(reschedule_requested_at)`
- `idx_appt_doctor_date_status` ON `appointments(doctor_id, date, status)`
- `idx_appt_doctor_pending_reschedule` ON `appointments(doctor_id, pending_reschedule_date, reschedule_status)`
- `idx_appt_doctor_requested_reschedule` ON `appointments(doctor_id, requested_reschedule_date, reschedule_status)`

### Migration Command
```sql
-- Back up the target database first, then run from the project root.
SOURCE database/migrations/phase10_reschedule_workflow.sql;
```

The active migration is at `database/migrations/phase10_reschedule_workflow.sql`. It adds the `suggested` enum value, original-request date/time fields, and doctor/date/reschedule indexes. Existing `pending` requests can be backfilled from `pending_reschedule_*`. For legacy rows already in `suggested` state, the earlier implementation overwrote the patient's original request; that lost value cannot be reconstructed safely. The migration leaves the new original-request fields NULL for those rows, and declining such a legacy suggestion asks the user to cancel and submit a new request rather than inventing historical data.

---

## API Changes

### New Endpoints

#### 1. POST `/api/appointments/request-reschedule.php`
- **Auth**: Patient or Admin
- **Input**: `{ appointment_id, new_date, new_time, reason? }`
- **Logic**: Validates appointment ownership, status, slot availability, then sets status to 'Reschedule Requested' and stores pending data
- **Response**: `{ success, message }`

#### 2. POST `/api/appointments/approve-reschedule.php`
- **Auth**: Doctor or Admin
- **Input**: `{ appointment_id, notes? }`
- **Logic**: Validates pending request, re-validates slot availability, updates appointment date/time, sets status to 'Confirmed'
- **Response**: `{ success, message }`

#### 3. POST `/api/appointments/reject-reschedule.php`
- **Auth**: Doctor or Admin
- **Input**: `{ appointment_id, notes? }`
- **Logic**: Returns appointment to 'Confirmed' status with original date/time, sets reschedule_status to 'rejected'
- **Response**: `{ success, message }`

#### 4. GET `/api/appointments/get-pending-reschedules.php`
- **Auth**: Doctor or Admin
- **Input**: None (query params optional)
- **Logic**: Returns pending requests (doctor-specific or all)
- **Response**: `{ success, requests[], count }`

### Existing Endpoints Affected
- None. All existing booking APIs remain unchanged.

---

## Service Changes

### 1. `services/RescheduleService.php` (NEW)

**Core methods:**
- `requestReschedule(int $appointmentId, string $newDate, string $newTime, string $reason): array`
  - Validates: appointment exists, belongs to patient, status is Confirmed, no pending reschedule, new slot != current slot, slot is available
  - Updates: status → 'Reschedule Requested', reschedule_status → 'pending', stores pending fields
  - Notifies: doctor, patient, all admins
  - Audits: full audit entry with old/new values

- `approveReschedule(int $appointmentId, string $doctorNotes): array`
  - Validates: pending request exists, actor is appointment's doctor
  - Re-validates slot availability (may have been taken since request)
  - Updates: date/time/time_range → accepted target, status → 'Confirmed', reschedule_status → 'approved'; original request remains in `requested_reschedule_*` for history
  - Notifies: patient, all admins
  - Audits: full audit entry

- `rejectReschedule(int $appointmentId, string $doctorNotes): array`
  - Validates: pending request exists, actor is appointment's doctor
  - Updates: status → 'Confirmed', reschedule_status → 'rejected' (original date/time preserved)
  - Notifies: patient, all admins
  - Audits: full audit entry

**Query methods:**
- `getPendingRequestsForDoctor(int $doctorId): array`
- `getAllPendingRequests(): array`
- `getRescheduleHistory(int $appointmentId): ?array`

### 2. `services/ScheduleService.php` (MODIFIED)

- `getBookedRanges()` changed from `private` to `public` to allow `RescheduleService` to use it
- `getBookedRanges()` reserves the original appointment slot and active pending target. While a doctor suggestion is awaiting the patient, it additionally reserves the patient's original requested target. These ranges count as one appointment for daily capacity.
- Schedule retrieval fills missing weekday rows in memory only; GET requests do not create schedule rows.
- Booking and reschedule mutations serialize on the doctor's user row and revalidate slot availability within the transaction.

### 3. `services/NotificationService.php` (MODIFIED)

**New constants:**
```php
const TYPE_RESCHEDULE_REQUEST   = 'reschedule_request';
const TYPE_RESCHEDULE_SUBMITTED = 'reschedule_submitted';
const TYPE_RESCHEDULE_APPROVED  = 'reschedule_approved';
const TYPE_RESCHEDULE_REJECTED  = 'reschedule_rejected';
```

---

## Notification Changes

### Notification Flow per Event

#### Patient Requests Reschedule
| Recipient | Type | Title | Message |
|-----------|------|-------|---------|
| Doctor | `reschedule_request` | "Reschedule Request" | "Ahmed Hassan requested to reschedule Appointment #145 from Jul 22 10:00 AM to Jul 24 1:30 PM." |
| All Admins | `reschedule_request` | "Reschedule Notification" | "Patient Ahmed Hassan requested to reschedule Appointment #145. Old: Jul 22 10:00 AM. New: Jul 24 1:30 PM" |
| Patient | `reschedule_submitted` | "Reschedule Request Submitted" | "Your reschedule request for Appointment #145 has been submitted. The doctor will review your request." |

#### Doctor Approves Reschedule
| Recipient | Type | Title | Message |
|-----------|------|-------|---------|
| Patient | `reschedule_approved` | "Reschedule Approved" | "Your reschedule request for Appointment #145 has been approved. New appointment: Jul 24, 2026 at 1:30 PM." |
| All Admins | `reschedule_approved` | "Reschedule Notification" | "Doctor Dr. Ahmed Hassan approved the reschedule request for Appointment #145." |

#### Doctor Rejects Reschedule
| Recipient | Type | Title | Message |
|-----------|------|-------|---------|
| Patient | `reschedule_rejected` | "Reschedule Declined" | "Your reschedule request for Appointment #145 has been declined. Your original appointment remains scheduled." |
| All Admins | `reschedule_rejected` | "Reschedule Notification" | "Doctor Dr. Ahmed Hassan rejected the reschedule request for Appointment #145." |

---

## Audit Changes

### New Audit Actions

All reschedule events are logged via `AuditService` (not `AdminAuditService`) to support any actor role (patient, doctor, admin).

| Action | Entity Type | Description |
|--------|-------------|-------------|
| `reschedule_request` | `appointment` | "Patient requested appointment reschedule. Old: Jul 22 10:00 AM. New: Jul 24 1:30 PM." |
| `reschedule_approved` | `appointment` | "Doctor approved appointment reschedule. Appointment #145 moved to 2026-07-24 at 13:30." |
| `reschedule_rejected` | `appointment` | "Doctor rejected appointment reschedule. Appointment #145 remains at original schedule." |

Each audit entry includes:
- `old_value`: JSON with original date/time
- `new_value`: JSON with requested/updated date/time
- `patient_id`: Contextual patient ID
- `doctor_id`: Contextual doctor ID
- `description`: Human-readable summary (never overwritten)

---

## UI Changes

### Patient Appointments Page (`pages/patient/appointments.html` + `assets/js/pages/patient/appointments.js`)
- Added "Rescheduled" filter chip to filter by `reschedule_requested` status
- Appointment cards now show **"🟡 Reschedule Requested"** badge when status is `Reschedule Requested`
- The `reschedule()` method now calls `BookingWizard.openReschedule()` instead of regular open

### Booking Wizard (`assets/js/components/booking-wizard.js`)
- **New mode**: `rescheduleMode` — when active:
  - Department selector is locked (disabled, visually dimmed)
  - Doctor selector is locked (disabled, visually dimmed)
  - Department/Doctor card grids are disabled (pointer-events: none, opacity: 0.6)
  - Modal title changes to "Reschedule Appointment"
  - Subtitle explains that doctor/department cannot be changed
  - Step labels updated to show "(locked)" for steps 1 and 2
  - Reason textarea appears on step 4 for "Reason for Rescheduling"
  - Submit sends to `request-reschedule.php` instead of `book.php`
  - Success message shows "Reschedule Request Submitted!" instead of "Appointment Booked!"
- On close: UI is restored to normal booking mode

### Doctor UI (via API)
- `GET /api/appointments/get-pending-reschedules.php` returns pending requests for the doctor
- Each pending request includes: appointment ID, patient name, doctor name, old date/time, requested new date/time, reason, timestamp

### Admin UI (via API)
- `GET /api/appointments/get-pending-reschedules.php` (admin role) returns ALL pending requests
- Audit log entries include the new reschedule actions for full traceability

---

## Workflow Diagram

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                        APPOINTMENT RESCHEDULE WORKFLOW                       │
│                       HealthBridge Hospital System                           │
└──────────────────────────────────────────────────────────────────────────────┘

  Appointment Status: "Confirmed"
  ─────────────────────────────────────

  [Patient clicks "Reschedule" on appointment card]
          │
          ▼
  ┌─────────────────────────────────────┐
  │     Booking Wizard Opens in         │
  │     RESCHEDULE MODE                  │
  │  • Department locked                │
  │  • Doctor locked                    │
  │  • Date/Time selectable             │
  │  • Reason field available           │
  └─────────────────────────────────────┘
          │
          ▼
  ┌─────────────────────────────────────┐
  │  POST /api/appointments/            │
  │  request-reschedule.php             │
  │                                     │
  │  Validates:                         │
  │  • Appointment exists               │
  │  • Belongs to patient               │
  │  • Status is "Confirmed"            │
  │  • No pending reschedule exists     │
  │  • New slot ≠ current slot          │
  │  • New slot is available (via       │
  │    ScheduleService.validateSlot)    │
  └─────────────────────────────────────┘
          │
          ▼
  ┌─────────────────────────────────────┐
  │  Appointment becomes:               │
  │  "Reschedule Requested"             │
  │  reschedule_status: "pending"       │
  │                                     │
  │  Original date/time preserved       │
  │  New date/time stored in:           │
  │    pending_reschedule_date          │
  │    pending_reschedule_time          │
  │                                     │
  │  NOTIFICATIONS SENT:                │
  │  • Doctor ← "New reschedule request"│
  │  • Patient ← "Request submitted"    │
  │  • Admins ← "Reschedule requested"  │
  │                                     │
  │  AUDIT LOG: reschedule_request      │
  └─────────────────────────────────────┘
          │
          ▼
  ┌─────────────────────────────────────┐
  │     DOCTOR DASHBOARD                │
  │  • Sees "Reschedule Request" badge  │
  │  • Views old vs requested schedule  │
  │  • Can Approve or Reject            │
  └─────────────────────────────────────┘
          │
          ├──────────── APPROVE ─────────────┤
          │                                    │
          ▼                                    ▼
  ┌──────────────────────────┐    ┌──────────────────────────┐
  │ POST /api/appointments/  │    │ POST /api/appointments/  │
  │ approve-reschedule.php   │    │ reject-reschedule.php    │
  │                          │    │                          │
  │ • Re-validates slot      │    │ • Status returns to      │
  │ • Updates date/time      │    │   "Confirmed"            │
  │ • Status: "Confirmed"    │    │ • Date/time unchanged    │
  │ • reschedule: "approved" │    │ • reschedule: "rejected" │
  │                          │    │                          │
  │ NOTIFICATIONS:           │    │ NOTIFICATIONS:           │
  │ • Patient ← "Approved"   │    │ • Patient ← "Declined"   │
  │ • Admins ← "Approved"    │    │ • Admins ← "Rejected"    │
  │                          │    │                          │
  │ AUDIT: approved          │    │ AUDIT: rejected          │
  └──────────────────────────┘    └──────────────────────────┘
          │                                    │
          ▼                                    ▼
  ┌──────────────────────────┐    ┌──────────────────────────┐
  │ OLD slot released        │    │ Original schedule        │
  │ NEW slot occupied        │    │ remains intact           │
  │ History preserved        │    │ No data lost             │
  └──────────────────────────┘    └──────────────────────────┘

  TIMELINE VIEW (Admin Audit Log):
  ┌─────────────────────────────────────────────────────────┐
  │  Jul 22 10:00 AM  │  Original booking (Appointment #145)│
  │  Jul 22 02:30 PM  │  Reschedule requested (→ Jul 24)    │
  │  Jul 22 02:45 PM  │  Doctor approved reschedule         │
  └─────────────────────────────────────────────────────────┘
```

---

## Manual Test Checklist

### Prerequisites
- [ ] Database migration applied (`database/migrations/phase10_reschedule_workflow.sql`)
- [ ] All new files deployed to server
- [ ] Clear browser cache

### Test 1: Patient Requests Reschedule (Happy Path)
- [ ] Log in as patient (e.g., `patient@healthbridge.com` / `password`)
- [ ] Navigate to Appointments page
- [ ] Verify "Confirmed" appointment shows "Reschedule" button
- [ ] Click "Reschedule"
- [ ] Verify Booking Wizard opens in **Reschedule Mode**:
  - [ ] Title says "Reschedule Appointment"
  - [ ] Department cards are dimmed/locked
  - [ ] Doctor cards are dimmed/locked
  - [ ] Only date/time/reason are editable
- [ ] Select a new date and time slot
- [ ] (Optional) Enter a reason for rescheduling
- [ ] Submit the form
- [ ] Verify success message "Reschedule Request Submitted!"
- [ ] Close the modal
- [ ] Verify appointment now shows **"🟡 Reschedule Requested"** badge
- [ ] Verify notification received: "Your reschedule request... has been submitted"

### Test 2: Patient Cannot Reschedule to Same Slot
- [ ] Log in as patient
- [ ] Click "Reschedule" on a confirmed appointment
- [ ] Select the SAME date and time as current appointment
- [ ] Submit the form
- [ ] Verify error message: "The new appointment time must be different from the current time."

### Test 3: Patient Cannot Reschedule Pending Appointment
- [ ] Create a pending appointment (not yet confirmed)
- [ ] Try to reschedule it
- [ ] Verify error: "Only confirmed appointments can be rescheduled."

### Test 4: Patient Cannot Submit Duplicate Reschedule Request
- [ ] Submit a reschedule request
- [ ] Try to submit another for the same appointment
- [ ] Verify error: "A reschedule request is already pending"

### Test 5: Doctor Approves Reschedule
- [ ] Log in as doctor (e.g., `ahmed.hassan@healthbridge.com` / `password`)
- [ ] Navigate to doctor dashboard
- [ ] Verify pending reschedule request appears
- [ ] View details: old schedule vs requested schedule
- [ ] Click "Approve"
- [ ] Verify success message
- [ ] Log in as patient
- [ ] Verify appointment now shows **"Confirmed"** with the new date/time
- [ ] Verify notification: "Your reschedule request... has been approved"

### Test 6: Doctor Rejects Reschedule
- [ ] Log in as doctor
- [ ] Find a pending reschedule request
- [ ] Click "Reject" (optionally provide a reason)
- [ ] Verify success message
- [ ] Log in as patient
- [ ] Verify appointment shows **"Confirmed"** with ORIGINAL date/time
- [ ] Verify notification: "Your reschedule request... has been declined"

### Test 7: Slot Availability Respects Reschedule
- [ ] Patient A requests reschedule for appointment at 10:00→14:00 (pending)
- [ ] Patient B tries to book the same doctor on same date
- [ ] Verify 14:00 slot is NOT available (because it's in pending_reschedule)
- [ ] Verify 10:00 slot IS available (the patient's old slot is still held)

### Test 8: Slot Re-validation on Approval
- [ ] Patient requests reschedule to slot X
- [ ] Before doctor responds, another patient books slot X
- [ ] Doctor tries to approve
- [ ] Verify error: "The requested time slot is no longer available"

### Test 9: Admin Audit Log
- [ ] Log in as admin
- [ ] Navigate to audit log
- [ ] Verify entries for:
  - [ ] "reschedule_request" with old/new date/time details
  - [ ] "reschedule_approved" or "reschedule_rejected"
- [ ] Verify all entries include patient_id, doctor_id, and human-readable description

### Test 10: Admin Can View All Pending Requests
- [ ] Log in as admin
- [ ] Call `GET /api/appointments/get-pending-reschedules.php`
- [ ] Verify all pending requests are returned (not filtered by doctor)

### Test 11: Doctor Can Only See Own Pending Requests
- [ ] Log in as Doctor A
- [ ] Call `GET /api/appointments/get-pending-reschedules.php`
- [ ] Verify only Doctor A's pending requests are returned

### Test 12: Appointment History Preserved
- [ ] After approval, query the appointment record directly
- [ ] Verify `reschedule_status` = 'approved'
- [ ] Verify `pending_reschedule_date` and `pending_reschedule_time` still contain the requested values
- [ ] Verify `reschedule_responded_at` and `reschedule_responded_by` are populated
- [ ] Verify audit log shows old date/time in `old_value`

### Test 13: UI Filter for Rescheduled Appointments
- [ ] Navigate to patient appointments page
- [ ] Click "Rescheduled" filter chip
- [ ] Verify only appointments with "🟡 Reschedule Requested" status appear

### Test 14: Normal Booking Still Works
- [ ] Log in as patient
- [ ] Click "Book Appointment"
- [ ] Verify Booking Wizard opens in normal mode (NOT reschedule mode)
- [ ] Complete a new booking
- [ ] Verify it works as before

### Test 15: Doctor Cannot Approve Another Doctor's Reschedule
- [ ] Log in as Doctor B
- [ ] Try to approve a reschedule for Doctor A's appointment
- [ ] Verify 403 or appropriate error

## Summary

All reschedule workflow components have been implemented with:
- **No duplicated booking logic** — reuses ScheduleService for slot validation
- **No duplicated notification logic** — reuses NotificationService
- **No appointment history lost** — original dates preserved, pending fields stored separately
- **Full audit trail** — every action logged with old/new values
- **Transaction safety** — approval/rejection use DB transactions
- **Role-based access** — patients can only request, doctors can only act on their appointments
</write_file>

## 80. HB-REL-001 — transactional reschedule notifications (6 November 2026)

### Implementation
- Moved the existing notification enqueue calls into the seven transaction-owning `RescheduleService` mutations: `requestReschedule`, `suggestReschedule`, `acceptRescheduleSuggestion`, `declineRescheduleSuggestion`, `cancelRescheduleRequest`, `approveReschedule`, and `rejectReschedule`.
- Each transition now records its existing audit event, enqueues the existing recipient notifications through `NotificationService::create()` on the same PDO transaction, and only then commits. A notification preference read or outbox insert failure therefore reaches the existing service catch/rollback path and prevents a state change from committing without its required event.
- After commit, a shared best-effort dispatcher calls `NotificationService::processOutbox(50)`. Delivery failure is logged and leaves committed events for the existing CLI worker; it does not convert a successful reschedule into a false failure.
- Preserved notification types, titles, message text, recipients, reference IDs, route payloads, authorization checks, state transitions, slot revalidation, and audit calls. No duplicate notification logic was added to API routes.
- The `approveReschedule` unavailable-slot path still rolls back before sending its explanatory notification. That branch commits no appointment mutation to couple to an outbox event, so its independent notification remains best-effort after rollback.
- Preserved the existing lock order: pre-transaction doctor ID lookup, doctor `users` row lock, then appointment row lock. `getAppointment(..., true)` continues to lock only `appointments` and read patient display name without a locking join. Notification preferences use a normal non-locking SELECT, and `notification_outbox` intentionally has no recipient FK.

### Verification
- PHP syntax: `services/RescheduleService.php` passed.
- Transaction-order assertions: **7/7** notification enqueue calls now precede the corresponding commit; all seven dispatch calls follow commit.
- Disposable MariaDB integration: **64/64 assertions passed** across all seven service operations. Each operation was tested both normally and with a temporary `BEFORE INSERT` trigger forcing an outbox failure. In every forced-failure case the service operation failed, appointment state rolled back, and no outbox row remained. In every normal case the expected transition committed and all newly queued events reached a delivered/skipped terminal state with no pending events.
- Two-connection lock-order regression passed: an archive-style transaction locked the patient row first and then waited on the appointment; a concurrent reschedule-style transaction locked doctor then appointment, performed the non-locking patient/preferences reads, and inserted to the no-FK outbox. Both transactions committed; no deadlock was reported.
- QA source was an isolated copy of the local synthetic `healthbridge` database (157 users, 486 appointments, 99 prescriptions, 545 notifications). A generated `phone_normalized` collation mismatch was corrected only in the disposable SQL dump using a simplified expression; the real schema and generated column were not changed.
- Disposable schema and test harnesses were removed after testing. The SQL dump and test logs remain under `C:\xampp85\migration-backups\healthbridge`.
- Read-only post-test counts from the real local database remained **157 users / 486 appointments / 99 prescriptions / 545 notifications / 0 outbox rows**. No real appointment, audit, notification, preference, or schema row was changed.
- No commit, push, deployment, hosted database operation, or branch change was performed. Branch and HEAD remain unchanged.

### Remaining under HB-REL-001
- HB-REL-001 remains **P2 open / partially mitigated**. Transactional enqueue now covers the five appointment routes, all seven reschedule workflow mutations, four prescription mutations, and clinical visit completion.
- Remaining producers still require individual transaction and lock-order reviews: other clinical writers, account lifecycle/archive/status paths, registration/doctor creation, ratings, settings, and support replies. Do not bulk-move them or undo prior archive/deadlock protections.
- Periodic CLI worker scheduling and monitoring remain operational requirements. No Windows scheduled task or hosted scheduler was configured in this local pass.
## 81. HB-REL-001 — additional clinical notification writers (6 November 2026)

### Implementation
- `api/medical/add-visit-note.php` now enqueues the existing `TYPE_VISIT_NOTE_ADDED` event after its visit-note write and required audit, but before the existing transaction commits. The event therefore commits or rolls back with note creation/update and audit. Prompt delivery is attempted through `processOutbox(50)` after commit; dispatch errors are logged and left for the CLI worker.
- `api/medical/update-record.php` now enqueues the existing `TYPE_MEDICAL_RECORD_UPDATED` event inside the same transaction as the record update and universal audit, then attempts delivery after commit. Doctor/admin message wording, type, recipient, and `medical_record` reference are unchanged.
- Preserved existing authorization, validation, account lock ordering, appointment locking, and audit semantics. Doctor clinical paths continue to lock doctor then patient then appointment where applicable; administrator medical-record updates lock the patient before the medical-record row. The outbox still has no recipient FK and preference reads remain non-locking.

### Verification
- PHP syntax passed **2/2** for the changed endpoints; targeted `git diff --check` passed (only existing LF/CRLF working-copy warnings).
- Disposable integration on the existing `healthbridge_repair_test` schema passed **23/23 assertions** across visit-note create, visit-note update, and medical-record update. Each operation was tested with a forced `BEFORE INSERT` outbox failure and with normal delivery. Forced failures rolled back the associated clinical write and audit with no outbox event; successful operations committed and delivered their existing notice.
- The older repair-test schema was missing Phase 15's outbox table and the current `visit_notes.follow_up_instructions` column. The Phase 15 outbox migration and the missing visit-note column were applied only to `healthbridge_repair_test` so the fixture matches the current app schema. Test data was restored afterward: appointment 1 has no QA note, patient 2's medical_notes value was restored, and QA admin_audit/notifications/outbox rows were removed. The test schema remains aligned with the current application.
- Read-only verification of the populated local `healthbridge` database remained **157 users / 486 appointments / 99 prescriptions / 545 notifications / 0 outbox rows / 216 visit notes / 124 medical records**. No populated local clinical, audit, or notification rows were changed.
- No commit, push, deployment, hosted database operation, or branch change occurred.

### Remaining under HB-REL-001
- Transactional enqueue now also covers the remaining identified clinical API notification producers (`add-visit-note.php` and `update-record.php`). The completed clinical group is appointment lifecycle, seven reschedule transitions, four prescription mutations, visit completion, visit-note create/update, and medical-record update.
- Next review group: account lifecycle/archive/status and registration/doctor creation notification producers. Preserve the account-first lock order and the prior archive/reschedule deadlock fixes; do not move notifications in bulk.
- Ratings, settings, and support replies still require transaction ownership review. Periodic CLI worker scheduling and monitoring remain outstanding; no Windows scheduled task was created.
## Maintenance status note - 6 November 2026

The seven reschedule transitions now enqueue their existing notification events in the same transaction as the reschedule mutation and audit, then attempt delivery after commit. The implementation and 64/64 disposable MariaDB assertions, including forced outbox-insert failures and lock-order regression, are recorded in Section 80 of HEALTHBRIDGE_REPAIR_PLAN_AND_HANDOFF.md. This transactional reliability note does not replace the remaining scheduling/slot, API, and browser regression work in the active engineering roadmap.

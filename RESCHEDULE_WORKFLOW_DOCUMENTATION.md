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
| `pending_reschedule_date` | DATE | NULL | Requested new date (pending approval) |
| `pending_reschedule_time` | VARCHAR(20) | NULL | Requested new time (pending approval) |
| `reschedule_reason` | TEXT | NULL | Patient reason for rescheduling |
| `reschedule_requested_at` | DATETIME | NULL | When the reschedule was requested |
| `reschedule_requested_by` | INT | NULL | User ID who requested the reschedule |
| `reschedule_status` | ENUM('none','pending','approved','rejected') | 'none' | Current reschedule workflow status |
| `reschedule_responded_at` | DATETIME | NULL | When doctor approved/rejected |
| `reschedule_responded_by` | INT | NULL | Doctor user ID who responded |
| `reschedule_response_notes` | TEXT | NULL | Doctor notes on approval/rejection |

### Modified ENUM on `appointments.status`
- Added: `'Reschedule Requested'`
- Current valid values: `'Pending', 'Confirmed', 'Cancelled', 'Reschedule Requested'`

### New Indexes
- `idx_appt_reschedule_status` ON `appointments(reschedule_status)`
- `idx_appt_reschedule_requested` ON `appointments(reschedule_requested_at)`

### Migration Command
```sql
-- Run the migration file
SOURCE database/migrations/phase10_reschedule_workflow.sql;
```

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
  - Updates: date/time/time_range → pending values, status → 'Confirmed', reschedule_status → 'approved'
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
- Status filter in `getBookedRanges()` now includes `'Reschedule Requested'` to exclude pending reschedule slots from availability

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
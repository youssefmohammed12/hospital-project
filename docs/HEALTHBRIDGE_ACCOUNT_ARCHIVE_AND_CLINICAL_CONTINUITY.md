# HealthBridge — Account archive and clinical continuity policy

**Status:** Local engineering policy  
**Updated:** 11 November 2026  
**Scope:** Patient/doctor account deactivation, appointment preservation, clinical access and prescription lifecycle.

This policy describes application behavior and safety boundaries. It is not a substitute for Egypt-specific health-record retention rules, hospital medical-director approval, pharmacy policy or legal review.

## 1. Patient archive is not clinical discharge

Archiving a patient account disables portal sign-in and advances the session version so existing sessions are revoked. It does **not** delete the patient, discharge them from care, or make their retained chart unavailable to authorized clinicians.

The patient row, EMR, allergies/medical history, visit drafts, visit notes, prescriptions, appointments with clinical evidence and audit history remain retained. A doctor must still be active and pass the existing clinical relationship/authorization checks to work with the patient record.

The patient's `is_active` field represents portal/account access. It is not a clinical-record deletion flag and is not used by `ClinicalAccessService::lockDoctorAndPatient()` to deny an otherwise authorized active clinician. The service still locks the patient row for referential and transaction ordering.

## 2. Appointment handling during patient or doctor account archive

Only an untouched booking may be automatically cancelled as part of the relevant archive/deactivation route. It must:
- be in an open booking state (Pending, Confirmed or Reschedule Requested);
- have no active/started/completed workflow state (workflow must be absent or Waiting); and
- have no saved `visit_drafts`, `visit_notes` or `prescriptions` record for that appointment.

A draft, clinical note or prescription is evidence that clinical work is attached to the encounter. The encounter is preserved even if an older workflow row still says Waiting or is missing. Completed workflow encounters are also preserved. Cancelled/Completed prescription records remain part of the encounter history.

This avoids silently relabelling a clinically documented encounter as an untouched booking during account maintenance.

## 3. Prescription lifecycle and inactive patient accounts

The current application supports three prescription statuses:
- **Active:** an order is still in progress and remains a clinical responsibility.
- **Completed:** the prescribed actions have been completed.
- **Cancelled:** the order was withdrawn before administration began.

An active, authorized prescribing doctor may complete or cancel an existing prescription after the patient's portal account is deactivated, when that is necessary to finish or reconcile the retained encounter. The patient's chart and encounter remain preserved. These actions continue to require the active doctor's identity/ownership checks and produce audit and notification-outbox records.

The current schema does not track individual medication administrations and has no distinct **Stopped** state. Do not use Cancelled to represent a treatment course that was partly administered and then discontinued. A future medication-administration/medication-statement workflow would be needed to model that distinction correctly.

## 4. Doctor deactivation requires clinical work to be resolved

Routine doctor archive, admin status-toggle disable and doctor self-deactivation must not strand work still assigned to that doctor. Deactivation is refused with HTTP 409 while any of the following exists:
- a Confirmed appointment with an In Progress or Ready to Complete workflow;
- an Active prescription owned by the doctor; or
- an unfinished visit draft owned by the doctor.

Resolve the visit and active prescription, and complete or remove the unfinished draft through the appropriate clinical workflow before deactivation. Completed/cancelled prescriptions, visit notes and historical encounters remain attached to the original patient/doctor/appointment records.

HealthBridge currently has no in-app clinical handover/reassignment workflow. The guard prevents routine deactivation from orphaning active work; it does not implement transfer to a covering clinician. An emergency access suspension/handover process would need an explicitly designed and audited workflow rather than silently bypassing these checks.

## 5. Concurrency and audit expectations

Prescription creation and mutation, visit-draft saves and doctor archive/deactivation serialize through the doctor account lock. Patient-related clinical writes also acquire the patient account lock before the appointment/clinical records. The archive/deactivation transaction preserves untouched-booking selection, status changes and audit entries atomically. Notification outbox delivery remains outside the business transaction where already implemented.

Account archive/disable and clinical updates must retain auditable actor, patient, doctor, appointment and prescription identity. Retained records must only be accessed for a legitimate, necessary care purpose by an authorized staff member. Access should be logged and reviewed under the hospital's actual governance process.

## 6. Implemented local routes

- `api/patients/delete.php`: soft-archives patient sign-in; preserves clinical records; cancels only untouched bookings.
- `api/doctors/delete.php`: blocks archive while active visits, Active prescriptions or unfinished drafts remain; preserves documented encounters.
- `api/doctors/toggle-status.php`: applies the same clinical-responsibility guard before disabling a doctor; reactivation remains supported.
- `api/settings/update.php`: applies the doctor clinical-responsibility guard to self-deactivation and preserves appointments with clinical artifacts for patient/doctor self-deactivation.
- `services/ClinicalAccessService.php`: keeps inactive patient portal status separate from clinical-record access for an authorized active doctor.
- `api/medical/save-visit-draft.php`: source-reviewed; locks the active doctor account before appointment/draft writes, serializing draft creation against doctor deactivation.
- `services/PrescriptionService.php`: prescription mutations lock the active doctor before patient/prescription records.

## 7. Local synthetic acceptance evidence

`C:\xampp85\migration-backups\healthbridge\hb-db-account-archive-clinical-preservation-rerun-20261111\RESULT.md`

The authenticated local HTTP policy runner passed **34/34 assertions** covering:
- patient archive revokes portal access but retains drafts, notes, prescriptions and associated appointments;
- only untouched bookings are cancelled;
- prescription completion and cancellation work for an existing retained-patient encounter after portal deactivation, with reason/audit/outbox persistence;
- doctor archive is blocked while Active prescriptions or visit drafts remain;
- doctor archive succeeds once active prescriptions are resolved and unfinished drafts are removed, while preserving documented encounter history; and
- doctor status-toggle disable is blocked while an Active prescription remains.

A separate authenticated HTTP runner for the actual `api/settings/update.php` doctor self-deactivation branch passed **20/20 assertions** (`C:\xampp85\migration-backups\healthbridge\hb-db-doctor-self-deactivation-guard-20261111\RESULT.md`). Wrong password returned 403; an in-progress visit, Active prescription, and unfinished draft each returned 409 without deactivation. After those obligations were resolved, self-deactivation succeeded, advanced the session version, preserved the completed prescription encounter, cancelled only untouched bookings, and persisted audit/notification-outbox records.

Patient self-deactivation through the same route then passed **19/19 authenticated HTTP assertions** (`C:\xampp85\migration-backups\healthbridge\hb-db-patient-self-deactivation-20261111\RESULT.md`). Wrong password returned 403 without changing the account; the correct password disabled portal access and advanced `session_version`; four untouched bookings were cancelled, while the encounter containing a visit note and Completed prescription remained Confirmed with medication items retained. The existing session was rejected with 403 and the self-deactivation audit record persisted. The disposable clone was restored exactly (26/26 rows/checksums/schema/AUTO_INCREMENT), `mysqlcheck` passed 26/26, and the clone was dropped.

The sole-active-administrator rule was separately source-reviewed (`hb-db-admin-self-deactivation-guard-20261111/RESULT.md`): the route locks the active-admin set in deterministic ID order and returns 409 when the requester is the last active admin. Its custom HTTP harness was blocked by the remote execution safety guard; **this is static evidence only, not runtime acceptance**. No administrator account or password was changed.

The disposable databases used by the 34/34 policy and 20/20 doctor self-deactivation suites were each restored from their own pre-test dump and exactly matched all 26 table row counts/extended checksums, schema hashes and 26/26 AUTO_INCREMENT values. `mysqlcheck` passed 26/26 tables on both; both clones were dropped and the retained synthetic baseline was preserved. The separate 19/19 patient self-deactivation clone was also restored exactly, passed `mysqlcheck` 26/26 and was dropped.

The earlier 37/37 and 31/31 archive-versus-prescription race suites document a previous behavior in which doctor archive was allowed before an active prescription mutation and could cancel an encounter that already had a prescription. Those expectations are **superseded** by the current clinical continuity policy and the combined 34/34 policy plus 20/20 self-deactivation acceptance above; retain the old folders as historical evidence, not as current expected behavior.

## 8. Remaining limits

- Patient self-deactivation has passed 19/19 authenticated HTTP assertions on a disposable clone. Sole-active-admin self-deactivation remains static source review only; its authenticated HTTP behavior was not tested.
- These are synthetic HTTP acceptance suites, not broad load testing, browser E2E, hosted verification or regulatory compliance certification.
- Confirm Egypt-specific retention/access/disposition duties with the applicable hospital/legal policy before real-world deployment.
- Medication administration and a distinct Stopped status are not modeled.
- In-app clinical handover/reassignment is not implemented; routine doctor deactivation is blocked while active work remains.


-- v2.0.3: enforce patient/doctor ownership against the same appointment row.
-- Existing synthetic ETL prechecks confirmed zero owner mismatches.
ALTER TABLE appointments
    ADD UNIQUE KEY uq_appointments_owner_identity (id, patient_user_id, doctor_user_id);

ALTER TABLE clinical_encounters
    ADD CONSTRAINT fk_encounters_appointment_owner
    FOREIGN KEY (appointment_id, patient_user_id, doctor_user_id)
    REFERENCES appointments (id, patient_user_id, doctor_user_id)
    ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE prescriptions
    ADD CONSTRAINT fk_prescriptions_appointment_owner
    FOREIGN KEY (appointment_id, patient_user_id, doctor_user_id)
    REFERENCES appointments (id, patient_user_id, doctor_user_id)
    ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE ratings
    ADD CONSTRAINT fk_ratings_appointment_owner
    FOREIGN KEY (appointment_id, patient_user_id, doctor_user_id)
    REFERENCES appointments (id, patient_user_id, doctor_user_id)
    ON DELETE RESTRICT ON UPDATE RESTRICT;

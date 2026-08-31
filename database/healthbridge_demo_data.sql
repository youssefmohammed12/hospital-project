-- ============================================================
-- HealthBridge Interactive Development & Demo Seed Data
--
-- Includes complete, realistic, interconnected hospital demo data:
-- - Preserves ALL Quick Login user accounts (admin, patient, doctors).
-- - Pre-populated first_name, last_name, and patient_number fields.
-- - Doctor profiles linked to departments & user accounts.
-- - Doctor weekly schedule settings & availability.
-- - Patient EMR medical records & preferences.
-- - Interconnected appointments, visit workflow states, visit notes,
--   prescriptions, ratings, and notification feeds.
-- ============================================================

-- Load base production schema & essential lookups first
SOURCE healthbridge_empty.sql;

USE healthbridge;

-- ============================================================
-- 1. DEMO USER ACCOUNTS (PRESERVING ALL QUICK LOGIN ACCOUNTS)
-- Password for all demo accounts: 'password'
-- ($2y$12$fih1RK9ubiSX4PTRokU7/ObwovH/e8JJr3HGFlTyE20L/y.r66YBu)
-- ============================================================

-- Quick Login Admin (id = 1)
INSERT IGNORE INTO users (id, name, first_name, last_name, email, password, role, is_active) VALUES
(1, 'Admin', 'System', 'Admin', 'admin@healthbridge.com', '$2y$12$fih1RK9ubiSX4PTRokU7/ObwovH/e8JJr3HGFlTyE20L/y.r66YBu', 'admin', 1);

-- Quick Login Patient (id = 2)
INSERT IGNORE INTO users (id, name, first_name, last_name, email, password, role, patient_number, phone, national_id, is_active) VALUES
(2, 'Ahmed Hassan', 'Ahmed', 'Hassan', 'patient@healthbridge.com', '$2y$12$fih1RK9ubiSX4PTRokU7/ObwovH/e8JJr3HGFlTyE20L/y.r66YBu', 'patient', 'HB-2026-000002', '+201001234567', '29501011234567', 1);

-- Quick Login Doctors (ids 3 to 10)
INSERT IGNORE INTO users (id, name, first_name, last_name, email, password, role, phone, national_id, is_active) VALUES
(3,  'Dr. Ahmed Hassan',  'Ahmed',  'Hassan',  'ahmed.hassan@healthbridge.com',  '$2y$12$fih1RK9ubiSX4PTRokU7/ObwovH/e8JJr3HGFlTyE20L/y.r66YBu', 'doctor', '+20123456789', '28001011234561', 1),
(4,  'Dr. Sarah Johnson', 'Sarah',  'Johnson', 'sarah.johnson@healthbridge.com', '$2y$12$fih1RK9ubiSX4PTRokU7/ObwovH/e8JJr3HGFlTyE20L/y.r66YBu', 'doctor', '+20123456780', '28201011234562', 1),
(5,  'Dr. Mohamed Ali',   'Mohamed', 'Ali',     'mohamed.ali@healthbridge.com',   '$2y$12$fih1RK9ubiSX4PTRokU7/ObwovH/e8JJr3HGFlTyE20L/y.r66YBu', 'doctor', '+20123456781', '28101011234563', 1),
(6,  'Dr. Fatima Nour',   'Fatima',  'Nour',    'fatima.nour@healthbridge.com',   '$2y$12$fih1RK9ubiSX4PTRokU7/ObwovH/e8JJr3HGFlTyE20L/y.r66YBu', 'doctor', '+20123456782', '28501011234564', 1),
(7,  'Dr. Karim Salah',   'Karim',   'Salah',   'karim.salah@healthbridge.com',   '$2y$12$fih1RK9ubiSX4PTRokU7/ObwovH/e8JJr3HGFlTyE20L/y.r66YBu', 'doctor', '+20123456783', '28301011234565', 1),
(8,  'Dr. Layla Ibrahim', 'Layla',   'Ibrahim', 'layla.ibrahim@healthbridge.com', '$2y$12$fih1RK9ubiSX4PTRokU7/ObwovH/e8JJr3HGFlTyE20L/y.r66YBu', 'doctor', '+20123456784', '28801011234566', 1),
(9,  'Dr. Omar Khaled',   'Omar',    'Khaled',  'omar.khaled@healthbridge.com',   '$2y$12$fih1RK9ubiSX4PTRokU7/ObwovH/e8JJr3HGFlTyE20L/y.r66YBu', 'doctor', '+20123456785', '28401011234567', 1),
(10, 'Dr. Nadia Rashid',  'Nadia',   'Rashid',  'nadia.rashid@healthbridge.com',  '$2y$12$fih1RK9ubiSX4PTRokU7/ObwovH/e8JJr3HGFlTyE20L/y.r66YBu', 'doctor', '+20123456786', '28701011234568', 1);

-- Additional Demo Patients (ids 11 to 13)
INSERT IGNORE INTO users (id, name, first_name, last_name, email, password, role, patient_number, phone, national_id, is_active) VALUES
(11, 'Mona El-Sayed',    'Mona',    'El-Sayed','mona.sayed@healthbridge.com',    '$2y$12$fih1RK9ubiSX4PTRokU7/ObwovH/e8JJr3HGFlTyE20L/y.r66YBu', 'patient', 'HB-2026-000011', '+201009876543', '29805051234567', 1),
(12, 'Youssef Mahmoud', 'Youssef', 'Mahmoud', 'youssef.mahmoud@healthbridge.com','$2y$12$fih1RK9ubiSX4PTRokU7/ObwovH/e8JJr3HGFlTyE20L/y.r66YBu', 'patient', 'HB-2026-000012', '+201005554433', '29208081234567', 1),
(13, 'Hana Farouk',     'Hana',    'Farouk',  'hana.farouk@healthbridge.com',   '$2y$12$fih1RK9ubiSX4PTRokU7/ObwovH/e8JJr3HGFlTyE20L/y.r66YBu', 'patient', 'HB-2026-000013', '+201002221100', '29912121234567', 1);

-- ============================================================
-- 2. DOCTOR PROFILES
-- ============================================================
INSERT IGNORE INTO doctors (user_id, name, specialty, department_id, rating, exp, available, emoji) VALUES
(3,  'Dr. Ahmed Hassan',  'Cardiology',    1, 4.9, 12, 1, 'fa-user-doctor'),
(4,  'Dr. Sarah Johnson', 'Dermatology',   2, 4.8,  8, 1, 'fa-user-doctor'),
(5,  'Dr. Mohamed Ali',   'Neurology',     3, 4.7, 15, 0, 'fa-user-doctor'),
(6,  'Dr. Fatima Nour',   'Pediatrics',    4, 4.9, 10, 1, 'fa-user-doctor'),
(7,  'Dr. Karim Salah',   'Orthopedics',   5, 4.6,  9, 1, 'fa-user-doctor'),
(8,  'Dr. Layla Ibrahim', 'Dentistry',     6, 4.8,  6, 1, 'fa-user-doctor'),
(9,  'Dr. Omar Khaled',   'Ophthalmology', 7, 4.7, 11, 0, 'fa-user-doctor'),
(10, 'Dr. Nadia Rashid',  'Gynecology',    8, 4.9, 14, 1, 'fa-user-doctor');

-- ============================================================
-- 3. PATIENT EMR MEDICAL RECORDS
-- ============================================================
INSERT IGNORE INTO medical_records 
(patient_id, blood_type, height_cm, weight_kg, date_of_birth, gender, governorate, city, address, allergies, chronic_diseases, current_medications, insurance_provider, insurance_number, emergency_contact_name, emergency_contact_rel, emergency_contact_phone, medical_notes) 
VALUES
(2,  'A+',  178.0, 75.5, '1995-01-01', 'Male',   'Cairo',     'Maadi',     '15 Road 9, Maadi, Cairo',            'Penicillin',    'Hypertension', 'Lisinopril 10mg',    'Misr Insurance',  'MI-998877', 'Fatma Hassan', 'Spouse', '+201009998877', 'Regular runner, no surgical history.'),
(11, 'O+',  165.0, 62.0, '1998-05-05', 'Female', 'Alexandria','Smouha',    '24 Fouad Street, Smouha',           'Dust, Shellfish', 'Asthma',       'Ventolin Inhaler',   'GIG Egypt',       'GIG-112233', 'Tarek El-Sayed', 'Father', '+201007776655', 'Mild seasonal allergy flare-ups.'),
(12, 'B+',  182.0, 84.0, '1992-08-08', 'Male',   'Giza',      'Dokki',     '8 El Tahrir St, Dokki',              'None',          'None',         'None',               'MetLife Egypt',   'ML-445566', 'Noha Mahmoud', 'Sister', '+201004443322', 'Annual checkup done.'),
(13, 'AB-', 160.0, 55.0, '1999-12-12', 'Female', 'Sharqia',   'Zagazig',   'El Gelaa Street, Zagazig',           'Sulfa Drugs',   'Anemia',       'Ferrous Sulfate',    'AXA Egypt',       'AXA-778899', 'Farouk Said', 'Father', '+201001112233', 'Follow up on iron levels.');

-- ============================================================
-- 4. DEMO APPOINTMENTS
-- ============================================================
INSERT IGNORE INTO appointments
(id, doctor_id, user_id, department_id, patient_name, department, doctor, date, time, status, notes)
VALUES
(1, 3, 2,  1, 'Ahmed Hassan', 'Cardiology',  'Dr. Ahmed Hassan',  '2026-06-15', '10:00 AM', 'Confirmed', 'Routine cardiology consultation'),
(2, 4, 11, 2, 'Mona El-Sayed', 'Dermatology', 'Dr. Sarah Johnson', '2026-06-16', '11:30 AM', 'Confirmed', 'Skin rash inspection'),
(3, 3, 2,  1, 'Ahmed Hassan', 'Cardiology',  'Dr. Ahmed Hassan',  '2026-06-10', '09:00 AM', 'Confirmed', 'Blood pressure follow up'),
(4, 4, 2,  2, 'Ahmed Hassan', 'Dermatology', 'Dr. Sarah Johnson', '2026-06-12', '11:00 AM', 'Confirmed', 'Dermatology consultation'),
(5, 6, 2,  4, 'Ahmed Hassan', 'Pediatrics',  'Dr. Fatima Nour',   '2026-06-08', '02:00 PM', 'Confirmed', 'General wellness check'),
(6, 4, 2,  2, 'Ahmed Hassan', 'Dermatology', 'Dr. Sarah Johnson', '2026-07-01', '02:00 PM', 'Pending',   'Follow up consultation');

-- ============================================================
-- 5. VISIT WORKFLOW STATES
-- ============================================================
INSERT IGNORE INTO visit_workflow (appointment_id, status, started_at, completed_at) VALUES
(1, 'Waiting',   NULL, NULL),
(2, 'Waiting',   NULL, NULL),
(3, 'Waiting',   NULL, NULL),
(4, 'Waiting',   NULL, NULL),
(5, 'Completed', '2026-06-08 14:00:00', '2026-06-08 14:30:00'),
(6, 'Waiting',   NULL, NULL);

-- ============================================================
-- 6. VISIT NOTES & CLINICAL RECORDS
-- ============================================================
INSERT IGNORE INTO visit_notes (appointment_id, patient_id, doctor_id, diagnosis, symptoms, treatment, doctor_notes) VALUES
(5, 2, 6, 'General Fitness & Normal Wellness', 'Mild fatigue', 'Rest and hydration. Multivitamin daily.', 'Patient is in good overall health.');

-- ============================================================
-- 7. PRESCRIPTIONS & PRESCRIPTION ITEMS
-- ============================================================
INSERT IGNORE INTO prescriptions (id, patient_id, doctor_id, appointment_id, notes, status) VALUES
(1, 2, 6, 5, 'Take medication after meals.', 'Completed');

INSERT IGNORE INTO prescription_items (prescription_id, medication_name, strength, dosage, frequency, duration, instructions, sort_order) VALUES
(1, 'Multivitamin Complex', '500mg', '1 tablet', 'Once daily', '30 days', 'Take in the morning after breakfast.', 1);

-- ============================================================
-- 8. RATINGS & REVIEWS
-- ============================================================
INSERT IGNORE INTO ratings (appointment_id, user_id, doctor_id, stars, review) VALUES
(5, 2, 6, 5, 'Dr. Fatima Nour was extremely attentive and gentle during the examination. Highly recommended!');

-- ============================================================
-- 9. DOCTOR SCHEDULE SETTINGS & WEEKLY HOURS
-- ============================================================
INSERT IGNORE INTO doctor_schedule_settings (doctor_id, appointment_duration, max_appointments_per_day, is_available)
SELECT id, 30, 25, 1 FROM users WHERE role = 'doctor';

INSERT IGNORE INTO doctor_schedule_weekly (doctor_id, day_of_week, start_time, end_time, is_working)
SELECT u.id, 1, '09:00', '17:00', 1 FROM users u WHERE u.role = 'doctor';
INSERT IGNORE INTO doctor_schedule_weekly (doctor_id, day_of_week, start_time, end_time, is_working)
SELECT u.id, 2, '09:00', '17:00', 1 FROM users u WHERE u.role = 'doctor';
INSERT IGNORE INTO doctor_schedule_weekly (doctor_id, day_of_week, start_time, end_time, is_working)
SELECT u.id, 3, '09:00', '17:00', 1 FROM users u WHERE u.role = 'doctor';
INSERT IGNORE INTO doctor_schedule_weekly (doctor_id, day_of_week, start_time, end_time, is_working)
SELECT u.id, 4, '09:00', '17:00', 1 FROM users u WHERE u.role = 'doctor';
INSERT IGNORE INTO doctor_schedule_weekly (doctor_id, day_of_week, start_time, end_time, is_working)
SELECT u.id, 5, '09:00', '17:00', 1 FROM users u WHERE u.role = 'doctor';
INSERT IGNORE INTO doctor_schedule_weekly (doctor_id, day_of_week, start_time, end_time, is_working)
SELECT u.id, 6, '09:00', '17:00', 0 FROM users u WHERE u.role = 'doctor';
INSERT IGNORE INTO doctor_schedule_weekly (doctor_id, day_of_week, start_time, end_time, is_working)
SELECT u.id, 7, '09:00', '17:00', 0 FROM users u WHERE u.role = 'doctor';

-- ============================================================
-- 10. USER PREFERENCES
-- ============================================================
INSERT IGNORE INTO user_preferences (user_id)
SELECT id FROM users;

-- ============================================================
-- 11. NOTIFICATIONS SEED
-- ============================================================
INSERT IGNORE INTO notifications (user_id, type, title, message, ref_type, ref_id) VALUES
(2, 'appointment_confirmed', 'Appointment Confirmed', 'Your appointment with Dr. Ahmed Hassan on 2026-06-15 at 10:00 AM has been confirmed.', 'appointment', 1),
(2, 'prescription_issued',   'New Prescription Issued', 'Dr. Fatima Nour has issued a prescription for your recent visit.', 'prescription', 1);

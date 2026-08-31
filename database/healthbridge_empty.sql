-- ============================================================
-- HealthBridge Fresh Production Database Setup
-- Includes Schema + Essential Lookup Data ONLY.
--
-- Contains:
-- 1. All 25 Master Tables
-- 2. Hospital Default Settings Row
-- 3. Essential Hospital Departments
-- 4. 27 Egyptian Governorates & Associated Cities Lookups
--
-- Zero Fake Users, Appointments, Patients, Doctors, or Records.
-- ============================================================

-- Load Schema
SOURCE healthbridge.sql;

USE healthbridge;

-- ============================================================
-- ESSENTIAL SEED DATA
-- ============================================================

-- 1. Default hospital settings (id = 1)
INSERT IGNORE INTO hospital_settings (id, hospital_name, hospital_phone, hospital_email, appointment_open_time, appointment_close_time, default_appointment_duration)
VALUES (1, 'HealthBridge Hospital', '+20123456789', 'info@healthbridge.com', '08:00', '22:00', 30);

-- 2. Default departments
INSERT IGNORE INTO departments (id, name, description, status) VALUES
(1, 'Cardiology', 'Diagnosis and treatment of heart and cardiovascular conditions', 'active'),
(2, 'Dermatology', 'Diagnosis and treatment of skin, hair, and nail conditions', 'active'),
(3, 'Neurology', 'Diagnosis and treatment of nervous system and brain disorders', 'active'),
(4, 'Pediatrics', 'Medical care for infants, children, and adolescents', 'active'),
(5, 'Orthopedics', 'Diagnosis and treatment of musculoskeletal system conditions', 'active'),
(6, 'Dentistry', 'Diagnosis and treatment of oral health and dental conditions', 'active'),
(7, 'Ophthalmology', 'Diagnosis and treatment of eye and vision conditions', 'active'),
(8, 'Gynecology', 'Women''s health and reproductive medicine', 'active'),
(9, 'General Practice', 'Primary care and general medical services', 'active');

-- 3. Egyptian Governorates
INSERT IGNORE INTO egypt_governorates (id, name, sort_order) VALUES
(1,  'Alexandria', 1),
(2,  'Aswan', 2),
(3,  'Asyut', 3),
(4,  'Beheira', 4),
(5,  'Beni Suef', 5),
(6,  'Cairo', 6),
(7,  'Dakahlia', 7),
(8,  'Damietta', 8),
(9,  'Faiyum', 9),
(10, 'Gharbia', 10),
(11, 'Giza', 11),
(12, 'Ismailia', 12),
(13, 'Kafr El Sheikh', 13),
(14, 'Luxor', 14),
(15, 'Matrouh', 15),
(16, 'Minya', 16),
(17, 'Monufia', 17),
(18, 'New Valley', 18),
(19, 'North Sinai', 19),
(20, 'Port Said', 20),
(21, 'Qalyubia', 21),
(22, 'Qena', 22),
(23, 'Red Sea', 23),
(24, 'Sharqia', 24),
(25, 'Sohag', 25),
(26, 'South Sinai', 26),
(27, 'Suez', 27);

-- 4. Egyptian Cities
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Downtown' AS name UNION SELECT 'Nasr City' UNION SELECT 'Maadi'
    UNION SELECT 'Heliopolis' UNION SELECT 'Zamalek' UNION SELECT 'Garden City'
    UNION SELECT 'Mohandessin' UNION SELECT 'Dokki' UNION SELECT 'Agouza'
    UNION SELECT 'Shubra' UNION SELECT 'Roda' UNION SELECT 'Abbassia'
) c WHERE g.name = 'Cairo';

INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Giza City' AS name UNION SELECT '6th October' UNION SELECT 'Sheikh Zayed'
    UNION SELECT 'Haram' UNION SELECT 'Faisal' UNION SELECT 'Imbaba'
    UNION SELECT 'Bulaq Dakrour' UNION SELECT 'Ossim' UNION SELECT 'Kerdasa'
) c WHERE g.name = 'Giza';

INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Alexandria Downtown' AS name UNION SELECT 'Smouha' UNION SELECT 'Stanly'
    UNION SELECT 'Sidi Gaber' UNION SELECT 'Gleem' UNION SELECT 'Miami'
    UNION SELECT 'Montazah' UNION SELECT 'Borg El Arab' UNION SELECT 'Agami'
    UNION SELECT 'Roshdy' UNION SELECT 'Louran' UNION SELECT 'Kafr Abdu'
) c WHERE g.name = 'Alexandria';

INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Zagazig' AS name UNION SELECT '10th of Ramadan' UNION SELECT 'Belbeis'
    UNION SELECT 'Abu Hammad' UNION SELECT 'Minya El Qamh' UNION SELECT 'Hihya'
    UNION SELECT 'Abu Kebir' UNION SELECT 'Faqous' UNION SELECT 'Kafr Saqr'
) c WHERE g.name = 'Sharqia';

INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Mansoura' AS name UNION SELECT 'Mit Ghamr' UNION SELECT 'Talkha'
    UNION SELECT 'Dikirnis' UNION SELECT 'Belqas' UNION SELECT 'Menia El Nasr'
    UNION SELECT 'Aga' UNION SELECT 'Sherbin' UNION SELECT 'Nabaroh'
) c WHERE g.name = 'Dakahlia';

INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Damanhur' AS name UNION SELECT 'Kafr El Dawwar' UNION SELECT 'Edku'
    UNION SELECT 'Rashid' UNION SELECT 'Abu El Matamir' UNION SELECT 'Abu Hummus'
    UNION SELECT 'Wadi El Natrun' UNION SELECT 'Hosh Issa' UNION SELECT 'Kom Hamada'
) c WHERE g.name = 'Beheira';

INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Tanta' AS name UNION SELECT 'El Mahalla El Kubra' UNION SELECT 'Kafr El Zayat'
    UNION SELECT 'Samanoud' UNION SELECT 'Basyoun' UNION SELECT 'Qutur'
    UNION SELECT 'Zifta' UNION SELECT 'Santa' UNION SELECT 'El Santeen'
) c WHERE g.name = 'Gharbia';

INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Shubra El Kheima' AS name UNION SELECT 'Banha' UNION SELECT 'Qalyub'
    UNION SELECT 'Kafr Shukr' UNION SELECT 'Tukh' UNION SELECT 'Qaha'
    UNION SELECT 'El Khanka' UNION SELECT 'Shibin El Qanater'
) c WHERE g.name = 'Qalyubia';

INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Shibin El Kom' AS name UNION SELECT 'Menouf' UNION SELECT 'Ashmoun'
    UNION SELECT 'Bagour' UNION SELECT 'Quesna' UNION SELECT 'Sadat City'
    UNION SELECT 'Berket El Saba' UNION SELECT 'Tala'
) c WHERE g.name = 'Monufia';

INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Minya' AS name UNION SELECT 'Mallawi' UNION SELECT 'Samalut'
    UNION SELECT 'Matay' UNION SELECT 'Beni Mazar' UNION SELECT 'Maghagha'
    UNION SELECT 'Abu Qirqas' UNION SELECT 'Deir Mawas'
) c WHERE g.name = 'Minya';

INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Asyut' AS name UNION SELECT 'Abnub' UNION SELECT 'Abu Tig'
    UNION SELECT 'El Badari' UNION SELECT 'Sahel Selim' UNION SELECT 'El Qusiya'
    UNION SELECT 'Manfalut' UNION SELECT 'Dairut'
) c WHERE g.name = 'Asyut';

INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Sohag' AS name UNION SELECT 'Akhmim' UNION SELECT 'Gerga'
    UNION SELECT 'El Balyana' UNION SELECT 'Sakulta' UNION SELECT 'Tahta'
    UNION SELECT 'Tima' UNION SELECT 'El Maragha'
) c WHERE g.name = 'Sohag';

INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Qena' AS name UNION SELECT 'Luxor' UNION SELECT 'Nag Hammadi'
    UNION SELECT 'Dishna' UNION SELECT 'Farshut' UNION SELECT 'Qus'
    UNION SELECT 'Abu Tesht'
) c WHERE g.name = 'Qena';

INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Aswan' AS name UNION SELECT 'Kom Ombo' UNION SELECT 'Edfu'
    UNION SELECT 'Nasr El Nuba' UNION SELECT 'Daraw'
) c WHERE g.name = 'Aswan';

INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Ismailia' AS name UNION SELECT 'Fayed' UNION SELECT 'El Qantara'
    UNION SELECT 'Abu Sultan' UNION SELECT 'El Tal El Kebir'
) c WHERE g.name = 'Ismailia';

INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Port Said Downtown' AS name UNION SELECT 'El Dawahy' UNION SELECT 'El Manakh'
    UNION SELECT 'El Arab' UNION SELECT 'El Sharg' UNION SELECT 'El Ganoub'
) c WHERE g.name = 'Port Said';

INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Suez' AS name UNION SELECT 'El Ganayen' UNION SELECT 'El Arbaeen'
    UNION SELECT 'El Suez' UNION SELECT 'Ataqa' UNION SELECT 'Faisal'
) c WHERE g.name = 'Suez';

INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Damietta' AS name UNION SELECT 'Ras El Bar' UNION SELECT 'Faraskour'
    UNION SELECT 'Kafr Saad' UNION SELECT 'El Zarqa' UNION SELECT 'Kafr El Battikh'
) c WHERE g.name = 'Damietta';

INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Kafr El Sheikh' AS name UNION SELECT 'Desouk' UNION SELECT 'Billa'
    UNION SELECT 'Fuwwah' UNION SELECT 'Metoubas' UNION SELECT 'Qallin'
    UNION SELECT 'Hamool' UNION SELECT 'El Riyadh'
) c WHERE g.name = 'Kafr El Sheikh';

INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Faiyum' AS name UNION SELECT 'Senuris' UNION SELECT 'Tamiya'
    UNION SELECT 'Ibsheway' UNION SELECT 'Etsa' UNION SELECT 'Youssef El Seddik'
) c WHERE g.name = 'Faiyum';

INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Beni Suef' AS name UNION SELECT 'El Fashn' UNION SELECT 'Beba'
    UNION SELECT 'Somasta' UNION SELECT 'Nasser' UNION SELECT 'El Wasta'
    UNION SELECT 'Ehnasia'
) c WHERE g.name = 'Beni Suef';

INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Luxor City' AS name UNION SELECT 'Karnak' UNION SELECT 'Armant'
    UNION SELECT 'Esna' UNION SELECT 'El Toud'
) c WHERE g.name = 'Luxor';

INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Marsa Matrouh' AS name UNION SELECT 'Siwa' UNION SELECT 'El Alamein'
    UNION SELECT 'El Dabaa' UNION SELECT 'El Negaila' UNION SELECT 'Sallum'
) c WHERE g.name = 'Matrouh';

INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Hurghada' AS name UNION SELECT 'El Gouna' UNION SELECT 'Safaga'
    UNION SELECT 'Marsa Alam' UNION SELECT 'Quseir' UNION SELECT 'Ras Gharib'
) c WHERE g.name = 'Red Sea';

INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Sharm El Sheikh' AS name UNION SELECT 'Dahab' UNION SELECT 'Nuweiba'
    UNION SELECT 'Taba' UNION SELECT 'St. Catherine' UNION SELECT 'Ras Sidr'
) c WHERE g.name = 'South Sinai';

INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Arish' AS name UNION SELECT 'Sheikh Zuweid' UNION SELECT 'Rafah'
    UNION SELECT 'Bir El Abd' UNION SELECT 'Nakhl' UNION SELECT 'El Hassana'
) c WHERE g.name = 'North Sinai';

INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Kharga' AS name UNION SELECT 'Dakhla' UNION SELECT 'Farafra'
    UNION SELECT 'Baris' UNION SELECT 'Mut' UNION SELECT 'El Qasr'
) c WHERE g.name = 'New Valley';

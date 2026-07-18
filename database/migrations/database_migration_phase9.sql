-- ============================================================
-- HealthBridge Phase 9 — Professional Authentication Migration
-- 
-- Changes:
--   1. Add patient_number to users table
--   2. Rename `name` to `first_name` / add `last_name`
--   3. Add national_id to users
--   4. Move patient-specific fields to medical_records
--   5. Add Egyptian governorates table for dropdown
--   6. Add cities table populated with governorate cities
-- ============================================================

-- Step 1: Add patient_number column to users
ALTER TABLE users
    ADD COLUMN patient_number VARCHAR(20) NULL UNIQUE AFTER role,
    ADD INDEX idx_users_patient_number (patient_number);

-- Step 2: Add first_name / last_name columns
ALTER TABLE users
    ADD COLUMN first_name VARCHAR(100) NULL AFTER name,
    ADD COLUMN last_name  VARCHAR(100) NULL AFTER first_name;

-- Step 3: Add national_id to users (unique identity verification field)
ALTER TABLE users
    ADD COLUMN national_id VARCHAR(14) NULL UNIQUE AFTER phone,
    ADD INDEX idx_users_national_id (national_id);

-- Step 4: Migrate existing data — split `name` into first_name / last_name
UPDATE users
SET first_name = TRIM(SUBSTRING_INDEX(name, ' ', 1)),
    last_name  = TRIM(SUBSTRING_INDEX(name, ' ', -1))
WHERE first_name IS NULL;

-- If name has only one word, set last_name to empty string
UPDATE users
SET last_name = ''
WHERE first_name = last_name AND first_name IS NOT NULL;

-- Step 5: Add patient-specific columns to medical_records (if not exist)
ALTER TABLE medical_records
    ADD COLUMN IF NOT EXISTS governorate       VARCHAR(100) NULL AFTER gender,
    ADD COLUMN IF NOT EXISTS city              VARCHAR(100) NULL AFTER governorate,
    ADD COLUMN IF NOT EXISTS address           TEXT         NULL AFTER city,
    ADD COLUMN IF NOT EXISTS insurance_provider VARCHAR(200) NULL AFTER current_medications,
    ADD COLUMN IF NOT EXISTS insurance_number  VARCHAR(100) NULL AFTER insurance_provider;

-- Step 6: Create Egyptian governorates reference table
CREATE TABLE IF NOT EXISTS egypt_governorates (
    id   INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 7: Create cities reference table
CREATE TABLE IF NOT EXISTS egypt_cities (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    governorate_id  INT          NOT NULL,
    name            VARCHAR(100) NOT NULL,
    INDEX idx_cities_governorate (governorate_id),
    CONSTRAINT fk_cities_governorate
        FOREIGN KEY (governorate_id) REFERENCES egypt_governorates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 8: Seed Egyptian governorates
INSERT IGNORE INTO egypt_governorates (name, sort_order) VALUES
('Alexandria', 1),
('Aswan', 2),
('Asyut', 3),
('Beheira', 4),
('Beni Suef', 5),
('Cairo', 6),
('Dakahlia', 7),
('Damietta', 8),
('Faiyum', 9),
('Gharbia', 10),
('Giza', 11),
('Ismailia', 12),
('Kafr El Sheikh', 13),
('Luxor', 14),
('Matrouh', 15),
('Minya', 16),
('Monufia', 17),
('New Valley', 18),
('North Sinai', 19),
('Port Said', 20),
('Qalyubia', 21),
('Qena', 22),
('Red Sea', 23),
('Sharqia', 24),
('Sohag', 25),
('South Sinai', 26),
('Suez', 27);

-- Step 9: Seed cities for each governorate
-- Cairo
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Downtown' AS name UNION SELECT 'Nasr City' UNION SELECT 'Maadi'
    UNION SELECT 'Heliopolis' UNION SELECT 'Zamalek' UNION SELECT 'Garden City'
    UNION SELECT 'Mohandessin' UNION SELECT 'Dokki' UNION SELECT 'Agouza'
    UNION SELECT 'Shubra' UNION SELECT 'Roda' UNION SELECT 'Abbassia'
) c WHERE g.name = 'Cairo';

-- Giza
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Giza City' AS name UNION SELECT '6th October' UNION SELECT 'Sheikh Zayed'
    UNION SELECT 'Haram' UNION SELECT 'Faisal' UNION SELECT 'Imbaba'
    UNION SELECT 'Bulaq Dakrour' UNION SELECT 'Ossim' UNION SELECT 'Kerdasa'
) c WHERE g.name = 'Giza';

-- Alexandria
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Alexandria Downtown' AS name UNION SELECT 'Smouha' UNION SELECT 'Stanly'
    UNION SELECT 'Sidi Gaber' UNION SELECT 'Gleem' UNION SELECT 'Miami'
    UNION SELECT 'Montazah' UNION SELECT 'Borg El Arab' UNION SELECT 'Agami'
    UNION SELECT 'Roshdy' UNION SELECT 'Louran' UNION SELECT 'Kafr Abdu'
) c WHERE g.name = 'Alexandria';

-- Sharqia
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Zagazig' AS name UNION SELECT '10th of Ramadan' UNION SELECT 'Belbeis'
    UNION SELECT 'Abu Hammad' UNION SELECT 'Minya El Qamh' UNION SELECT 'Hihya'
    UNION SELECT 'Abu Kebir' UNION SELECT 'Faqous' UNION SELECT 'Kafr Saqr'
) c WHERE g.name = 'Sharqia';

-- Dakahlia
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Mansoura' AS name UNION SELECT 'Mit Ghamr' UNION SELECT 'Talkha'
    UNION SELECT 'Dikirnis' UNION SELECT 'Belqas' UNION SELECT 'Menia El Nasr'
    UNION SELECT 'Aga' UNION SELECT 'Sherbin' UNION SELECT 'Nabaroh'
) c WHERE g.name = 'Dakahlia';

-- Beheira
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Damanhur' AS name UNION SELECT 'Kafr El Dawwar' UNION SELECT 'Edku'
    UNION SELECT 'Rashid' UNION SELECT 'Abu El Matamir' UNION SELECT 'Abu Hummus'
    UNION SELECT 'Wadi El Natrun' UNION SELECT 'Hosh Issa' UNION SELECT 'Kom Hamada'
) c WHERE g.name = 'Beheira';

-- Gharbia
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Tanta' AS name UNION SELECT 'El Mahalla El Kubra' UNION SELECT 'Kafr El Zayat'
    UNION SELECT 'Samanoud' UNION SELECT 'Basyoun' UNION SELECT 'Qutur'
    UNION SELECT 'Zifta' UNION SELECT 'Santa' UNION SELECT 'El Santeen'
) c WHERE g.name = 'Gharbia';

-- Qalyubia
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Shubra El Kheima' AS name UNION SELECT 'Banha' UNION SELECT 'Qalyub'
    UNION SELECT 'Kafr Shukr' UNION SELECT 'Tukh' UNION SELECT 'Qaha'
    UNION SELECT 'El Khanka' UNION SELECT 'Shibin El Qanater'
) c WHERE g.name = 'Qalyubia';

-- Monufia
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Shibin El Kom' AS name UNION SELECT 'Menouf' UNION SELECT 'Ashmoun'
    UNION SELECT 'Bagour' UNION SELECT 'Quesna' UNION SELECT 'Sadat City'
    UNION SELECT 'Berket El Saba' UNION SELECT 'Tala'
) c WHERE g.name = 'Monufia';

-- Minya
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Minya' AS name UNION SELECT 'Mallawi' UNION SELECT 'Samalut'
    UNION SELECT 'Matay' UNION SELECT 'Beni Mazar' UNION SELECT 'Maghagha'
    UNION SELECT 'Abu Qirqas' UNION SELECT 'Deir Mawas'
) c WHERE g.name = 'Minya';

-- Asyut
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Asyut' AS name UNION SELECT 'Abnub' UNION SELECT 'Abu Tig'
    UNION SELECT 'El Badari' UNION SELECT 'Sahel Selim' UNION SELECT 'El Qusiya'
    UNION SELECT 'Manfalut' UNION SELECT 'Dairut'
) c WHERE g.name = 'Asyut';

-- Sohag
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Sohag' AS name UNION SELECT 'Akhmim' UNION SELECT 'Gerga'
    UNION SELECT 'El Balyana' UNION SELECT 'Sakulta' UNION SELECT 'Tahta'
    UNION SELECT 'Tima' UNION SELECT 'El Maragha'
) c WHERE g.name = 'Sohag';

-- Qena
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Qena' AS name UNION SELECT 'Luxor' UNION SELECT 'Nag Hammadi'
    UNION SELECT 'Dishna' UNION SELECT 'Farshut' UNION SELECT 'Qus'
    UNION SELECT 'Abu Tesht'
) c WHERE g.name = 'Qena';

-- Aswan
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Aswan' AS name UNION SELECT 'Kom Ombo' UNION SELECT 'Edfu'
    UNION SELECT 'Nasr El Nuba' UNION SELECT 'Daraw'
) c WHERE g.name = 'Aswan';

-- Ismailia
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Ismailia' AS name UNION SELECT 'Fayed' UNION SELECT 'El Qantara'
    UNION SELECT 'Abu Sultan' UNION SELECT 'El Tal El Kebir'
) c WHERE g.name = 'Ismailia';

-- Port Said
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Port Said Downtown' AS name UNION SELECT 'El Dawahy' UNION SELECT 'El Manakh'
    UNION SELECT 'El Arab' UNION SELECT 'El Sharg' UNION SELECT 'El Ganoub'
) c WHERE g.name = 'Port Said';

-- Suez
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Suez' AS name UNION SELECT 'El Ganayen' UNION SELECT 'El Arbaeen'
    UNION SELECT 'El Suez' UNION SELECT 'Ataqa' UNION SELECT 'Faisal'
) c WHERE g.name = 'Suez';

-- Damietta
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Damietta' AS name UNION SELECT 'Ras El Bar' UNION SELECT 'Faraskour'
    UNION SELECT 'Kafr Saad' UNION SELECT 'El Zarqa' UNION SELECT 'Kafr El Battikh'
) c WHERE g.name = 'Damietta';

-- Kafr El Sheikh
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Kafr El Sheikh' AS name UNION SELECT 'Desouk' UNION SELECT 'Billa'
    UNION SELECT 'Fuwwah' UNION SELECT 'Metoubas' UNION SELECT 'Qallin'
    UNION SELECT 'Hamool' UNION SELECT 'El Riyadh'
) c WHERE g.name = 'Kafr El Sheikh';

-- Faiyum
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Faiyum' AS name UNION SELECT 'Senuris' UNION SELECT 'Tamiya'
    UNION SELECT 'Ibsheway' UNION SELECT 'Etsa' UNION SELECT 'Youssef El Seddik'
) c WHERE g.name = 'Faiyum';

-- Beni Suef
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Beni Suef' AS name UNION SELECT 'El Fashn' UNION SELECT 'Beba'
    UNION SELECT 'Somasta' UNION SELECT 'Nasser' UNION SELECT 'El Wasta'
    UNION SELECT 'Ehnasia'
) c WHERE g.name = 'Beni Suef';

-- Luxor
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Luxor City' AS name UNION SELECT 'Karnak' UNION SELECT 'Armant'
    UNION SELECT 'Esna' UNION SELECT 'El Toud'
) c WHERE g.name = 'Luxor';

-- Matrouh
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Marsa Matrouh' AS name UNION SELECT 'Siwa' UNION SELECT 'El Alamein'
    UNION SELECT 'El Dabaa' UNION SELECT 'El Negaila' UNION SELECT 'Sallum'
) c WHERE g.name = 'Matrouh';

-- Red Sea
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Hurghada' AS name UNION SELECT 'El Gouna' UNION SELECT 'Safaga'
    UNION SELECT 'Marsa Alam' UNION SELECT 'Quseir' UNION SELECT 'Ras Gharib'
) c WHERE g.name = 'Red Sea';

-- South Sinai
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Sharm El Sheikh' AS name UNION SELECT 'Dahab' UNION SELECT 'Nuweiba'
    UNION SELECT 'Taba' UNION SELECT 'St. Catherine' UNION SELECT 'Ras Sidr'
) c WHERE g.name = 'South Sinai';

-- North Sinai
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Arish' AS name UNION SELECT 'Sheikh Zuweid' UNION SELECT 'Rafah'
    UNION SELECT 'Bir El Abd' UNION SELECT 'Nakhl' UNION SELECT 'El Hassana'
) c WHERE g.name = 'North Sinai';

-- New Valley
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
CROSS JOIN (
    SELECT 'Kharga' AS name UNION SELECT 'Dakhla' UNION SELECT 'Farafra'
    UNION SELECT 'Baris' UNION SELECT 'Mut' UNION SELECT 'El Qasr'
) c WHERE g.name = 'New Valley';
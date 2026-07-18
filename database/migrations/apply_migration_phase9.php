<?php
/**
 * HealthBridge — Apply Phase 9 Migration
 * Adds patient_number, first_name, last_name, national_id columns to users,
 * patient-specific columns to medical_records, and creates egypt_governorates/egypt_cities tables.
 */

require_once __DIR__ . '/../../includes/auth.php';

echo "=== HealthBridge Phase 9 Migration ===\n\n";
$db = getDB();

try {
    // Step 1: Add patient_number to users
    echo "[1/7] Adding patient_number column...\n";
    $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'patient_number'");
    if (!$stmt->fetch()) {
        $db->exec("ALTER TABLE users
            ADD COLUMN patient_number VARCHAR(20) NULL UNIQUE AFTER role,
            ADD INDEX idx_users_patient_number (patient_number)");
        echo "  ✓ patient_number column added\n";
    } else {
        echo "  ✓ patient_number column already exists\n";
    }

    // Step 2: Add first_name / last_name
    echo "[2/7] Adding first_name / last_name columns...\n";
    $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'first_name'");
    if (!$stmt->fetch()) {
        $db->exec("ALTER TABLE users
            ADD COLUMN first_name VARCHAR(100) NULL AFTER name,
            ADD COLUMN last_name  VARCHAR(100) NULL AFTER first_name");
        echo "  ✓ first_name / last_name columns added\n";

        // Migrate existing data
        $db->exec("UPDATE users
            SET first_name = TRIM(SUBSTRING_INDEX(name, ' ', 1)),
                last_name  = TRIM(SUBSTRING_INDEX(name, ' ', -1))
            WHERE first_name IS NULL");
        $db->exec("UPDATE users SET last_name = '' WHERE first_name = last_name AND first_name IS NOT NULL");
        echo "  ✓ Existing data migrated (name → first_name / last_name)\n";
    } else {
        echo "  ✓ first_name / last_name columns already exist\n";
    }

    // Step 3: Add national_id to users
    echo "[3/7] Adding national_id column...\n";
    $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'national_id'");
    if (!$stmt->fetch()) {
        $db->exec("ALTER TABLE users
            ADD COLUMN national_id VARCHAR(14) NULL UNIQUE AFTER phone,
            ADD INDEX idx_users_national_id (national_id)");
        echo "  ✓ national_id column added\n";
    } else {
        echo "  ✓ national_id column already exists\n";
    }

    // Step 4: Add columns to medical_records
    echo "[4/7] Adding patient-specific columns to medical_records...\n";
    $columnsToAdd = [
        'governorate'       => 'VARCHAR(100) NULL AFTER gender',
        'city'              => 'VARCHAR(100) NULL AFTER governorate',
        'address'           => 'TEXT NULL AFTER city',
        'insurance_provider' => 'VARCHAR(200) NULL AFTER current_medications',
        'insurance_number'  => 'VARCHAR(100) NULL AFTER insurance_provider',
    ];

    $existingCols = [];
    $stmt = $db->query("SHOW COLUMNS FROM medical_records");
    foreach ($stmt as $row) {
        $existingCols[$row['Field']] = true;
    }

    foreach ($columnsToAdd as $colName => $colDef) {
        if (!isset($existingCols[$colName])) {
            $db->exec("ALTER TABLE medical_records ADD COLUMN {$colName} {$colDef}");
            echo "  ✓ {$colName} added\n";
        } else {
            echo "  ✓ {$colName} already exists\n";
        }
    }

    // Step 5: Create egypt_governorates table
    echo "[5/7] Creating egypt_governorates table...\n";
    $db->exec("CREATE TABLE IF NOT EXISTS egypt_governorates (
        id   INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        sort_order INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  ✓ egypt_governorates table created\n";

    // Step 6: Create egypt_cities table
    echo "[6/7] Creating egypt_cities table...\n";
    $db->exec("CREATE TABLE IF NOT EXISTS egypt_cities (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        governorate_id  INT          NOT NULL,
        name            VARCHAR(100) NOT NULL,
        INDEX idx_cities_governorate (governorate_id),
        CONSTRAINT fk_cities_governorate
            FOREIGN KEY (governorate_id) REFERENCES egypt_governorates(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  ✓ egypt_cities table created\n";

    // Step 7: Seed governorates and cities
    echo "[7/7] Seeding reference data...\n";

    // Check if already seeded
    $stmt = $db->query("SELECT COUNT(*) FROM egypt_governorates");
    $count = (int)$stmt->fetchColumn();

    if ($count === 0) {
        // Seed governorates
        $governorates = [
            ['Alexandria', 1], ['Aswan', 2], ['Asyut', 3], ['Beheira', 4],
            ['Beni Suef', 5], ['Cairo', 6], ['Dakahlia', 7], ['Damietta', 8],
            ['Faiyum', 9], ['Gharbia', 10], ['Giza', 11], ['Ismailia', 12],
            ['Kafr El Sheikh', 13], ['Luxor', 14], ['Matrouh', 15], ['Minya', 16],
            ['Monufia', 17], ['New Valley', 18], ['North Sinai', 19], ['Port Said', 20],
            ['Qalyubia', 21], ['Qena', 22], ['Red Sea', 23], ['Sharqia', 24],
            ['Sohag', 25], ['South Sinai', 26], ['Suez', 27],
        ];

        $stmt = $db->prepare("INSERT IGNORE INTO egypt_governorates (name, sort_order) VALUES (?, ?)");
        foreach ($governorates as $gov) {
            $stmt->execute($gov);
        }
        echo "  ✓ Governorates seeded ({$count} → " . count($governorates) . ")\n";

        // Seed cities (simplified - main cities per governorate)
        $cityData = [
            'Cairo' => ['Downtown', 'Nasr City', 'Maadi', 'Heliopolis', 'Zamalek', 'Garden City', 'Mohandessin', 'Dokki', 'Agouza', 'Shubra', 'Roda', 'Abbassia'],
            'Giza' => ['Giza City', '6th October', 'Sheikh Zayed', 'Haram', 'Faisal', 'Imbaba', 'Bulaq Dakrour', 'Ossim', 'Kerdasa'],
            'Alexandria' => ['Downtown', 'Smouha', 'Stanly', 'Sidi Gaber', 'Gleem', 'Miami', 'Montazah', 'Borg El Arab', 'Agami', 'Roshdy', 'Louran'],
            'Sharqia' => ['Zagazig', '10th of Ramadan', 'Belbeis', 'Abu Hammad', 'Minya El Qamh', 'Hihya', 'Abu Kebir', 'Faqous', 'Kafr Saqr'],
            'Dakahlia' => ['Mansoura', 'Mit Ghamr', 'Talkha', 'Dikirnis', 'Belqas', 'Menia El Nasr', 'Aga', 'Sherbin', 'Nabaroh'],
            'Beheira' => ['Damanhur', 'Kafr El Dawwar', 'Edku', 'Rashid', 'Abu El Matamir', 'Abu Hummus', 'Wadi El Natrun', 'Hosh Issa', 'Kom Hamada'],
            'Gharbia' => ['Tanta', 'El Mahalla El Kubra', 'Kafr El Zayat', 'Samanoud', 'Basyoun', 'Qutur', 'Zifta', 'Santa'],
            'Qalyubia' => ['Shubra El Kheima', 'Banha', 'Qalyub', 'Kafr Shukr', 'Tukh', 'Qaha', 'El Khanka', 'Shibin El Qanater'],
            'Monufia' => ['Shibin El Kom', 'Menouf', 'Ashmoun', 'Bagour', 'Quesna', 'Sadat City', 'Berket El Saba', 'Tala'],
            'Minya' => ['Minya', 'Mallawi', 'Samalut', 'Matay', 'Beni Mazar', 'Maghagha', 'Abu Qirqas', 'Deir Mawas'],
            'Asyut' => ['Asyut', 'Abnub', 'Abu Tig', 'El Badari', 'Sahel Selim', 'El Qusiya', 'Manfalut', 'Dairut'],
            'Sohag' => ['Sohag', 'Akhmim', 'Gerga', 'El Balyana', 'Sakulta', 'Tahta', 'Tima', 'El Maragha'],
            'Qena' => ['Qena', 'Nag Hammadi', 'Dishna', 'Farshut', 'Qus', 'Abu Tesht'],
            'Aswan' => ['Aswan', 'Kom Ombo', 'Edfu', 'Nasr El Nuba', 'Daraw'],
            'Ismailia' => ['Ismailia', 'Fayed', 'El Qantara', 'Abu Sultan', 'El Tal El Kebir'],
            'Port Said' => ['Downtown', 'El Dawahy', 'El Manakh', 'El Arab', 'El Sharg', 'El Ganoub'],
            'Suez' => ['Suez', 'El Ganayen', 'El Arbaeen', 'El Suez', 'Ataqa', 'Faisal'],
            'Damietta' => ['Damietta', 'Ras El Bar', 'Faraskour', 'Kafr Saad', 'El Zarqa', 'Kafr El Battikh'],
            'Kafr El Sheikh' => ['Kafr El Sheikh', 'Desouk', 'Billa', 'Fuwwah', 'Metoubas', 'Qallin', 'Hamool', 'El Riyadh'],
            'Faiyum' => ['Faiyum', 'Senuris', 'Tamiya', 'Ibsheway', 'Etsa', 'Youssef El Seddik'],
            'Beni Suef' => ['Beni Suef', 'El Fashn', 'Beba', 'Somasta', 'Nasser', 'El Wasta', 'Ehnasia'],
            'Luxor' => ['Luxor City', 'Karnak', 'Armant', 'Esna', 'El Toud'],
            'Matrouh' => ['Marsa Matrouh', 'Siwa', 'El Alamein', 'El Dabaa', 'El Negaila', 'Sallum'],
            'Red Sea' => ['Hurghada', 'El Gouna', 'Safaga', 'Marsa Alam', 'Quseir', 'Ras Gharib'],
            'South Sinai' => ['Sharm El Sheikh', 'Dahab', 'Nuweiba', 'Taba', 'St. Catherine', 'Ras Sidr'],
            'North Sinai' => ['Arish', 'Sheikh Zuweid', 'Rafah', 'Bir El Abd', 'Nakhl', 'El Hassana'],
            'New Valley' => ['Kharga', 'Dakhla', 'Farafra', 'Baris', 'Mut', 'El Qasr'],
        ];

        $govStmt = $db->query("SELECT id, name FROM egypt_governorates");
        $govMap = [];
        foreach ($govStmt as $row) {
            $govMap[$row['name']] = (int)$row['id'];
        }

        $cityStmt = $db->prepare("INSERT IGNORE INTO egypt_cities (governorate_id, name) VALUES (?, ?)");
        $totalCities = 0;
        foreach ($cityData as $govName => $cities) {
            if (isset($govMap[$govName])) {
                foreach ($cities as $city) {
                    $cityStmt->execute([$govMap[$govName], $city]);
                    $totalCities++;
                }
            }
        }
        echo "  ✓ Cities seeded ({$totalCities} cities)\n";
    } else {
        echo "  ✓ Reference data already seeded ({$count} governorates)\n";
    }

    // Step 8: Add unique constraint on phone
    echo "[8/7] Adding unique constraint on phone...\n";
    try {
        // First check for duplicates before adding unique constraint
        $stmt = $db->query("SELECT phone, COUNT(*) as cnt FROM users WHERE phone IS NOT NULL AND phone != '' GROUP BY phone HAVING cnt > 1");
        $dupes = $stmt->fetchAll();
        if (count($dupes) > 0) {
            echo "  ⚠ Found " . count($dupes) . " duplicate phone numbers. Resolving by appending suffix...\n";
            $fixStmt = $db->prepare("UPDATE users SET phone = CONCAT(phone, '_dup_', id) WHERE phone = ? AND id != (SELECT MIN(id) FROM users WHERE phone = ?)");
            foreach ($dupes as $dup) {
                $fixStmt->execute([$dup['phone'], $dup['phone']]);
            }
        }
        
        $db->exec("ALTER TABLE users ADD CONSTRAINT uq_users_phone UNIQUE (phone)");
        echo "  ✓ Unique constraint added on phone\n";
    } catch (Exception $e) {
        // If constraint already exists, just note it
        echo "  ✓ Unique constraint on phone already exists or could not be added (may need manual review)\n";
        error_log('Phone unique constraint error: ' . $e->getMessage());
    }

    echo "\n=== Phase 9 Migration Complete! ===\n";
    echo "Don't forget to update login.html from the root level redirect if needed.\n";

} catch (Exception $e) {
    echo "\n✗ Migration Error: " . $e->getMessage() . "\n";
    exit(1);
}
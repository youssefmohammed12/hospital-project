<?php
declare(strict_types=1);

/**
 * Isolated unit coverage for the patient-facing booking availability DTO.
 * This test calls the pure status builder directly and does not connect to a DB.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(10);
}

require_once __DIR__ . '/../services/PatientPortalService.php';

$reflection = new ReflectionClass(PatientPortalService::class);
$service = $reflection->newInstanceWithoutConstructor();
$builder = $reflection->getMethod('buildHospitalStatus');
$timezone = new DateTimeZone('Africa/Cairo');

$cases = [
    [
        'name' => 'Sunday 09:22 — closure reason must be weekend, not outside-hours',
        'now' => new DateTimeImmutable('2026-11-22 09:22:00', $timezone),
        'expected' => ['is_open' => false, 'closed_reason' => 'weekend', 'day_name' => 'Sunday'],
    ],
    [
        'name' => 'Saturday 09:22 — weekend remains closed during listed hours',
        'now' => new DateTimeImmutable('2026-11-21 09:22:00', $timezone),
        'expected' => ['is_open' => false, 'closed_reason' => 'weekend', 'day_name' => 'Saturday'],
    ],
    [
        'name' => 'Monday 09:22 — open inside configured hours',
        'now' => new DateTimeImmutable('2026-11-23 09:22:00', $timezone),
        'expected' => ['is_open' => true, 'closed_reason' => null, 'day_name' => 'Monday'],
    ],
    [
        'name' => 'Friday 21:59 — open until, but not including, close time',
        'now' => new DateTimeImmutable('2026-11-27 21:59:00', $timezone),
        'expected' => ['is_open' => true, 'closed_reason' => null, 'day_name' => 'Friday'],
    ],
    [
        'name' => 'Monday 07:59 — outside opening hours',
        'now' => new DateTimeImmutable('2026-11-23 07:59:00', $timezone),
        'expected' => ['is_open' => false, 'closed_reason' => 'outside_hours', 'day_name' => 'Monday'],
    ],
    [
        'name' => 'Monday 22:00 — closing time is exclusive',
        'now' => new DateTimeImmutable('2026-11-23 22:00:00', $timezone),
        'expected' => ['is_open' => false, 'closed_reason' => 'outside_hours', 'day_name' => 'Monday'],
    ],
];

$failures = [];
foreach ($cases as $case) {
    $actual = $builder->invoke($service, 'HealthBridge Hospital', '08:00:00', '22:00:00', $case['now']);
    foreach ($case['expected'] as $key => $expected) {
        if (($actual[$key] ?? null) !== $expected) {
            $failures[] = [
                'case' => $case['name'],
                'key' => $key,
                'expected' => $expected,
                'actual' => $actual[$key] ?? null,
            ];
        }
    }
    if (($actual['open_time'] ?? null) !== '08:00' || ($actual['close_time'] ?? null) !== '22:00') {
        $failures[] = [
            'case' => $case['name'],
            'key' => 'normalized_hours',
            'expected' => ['open_time' => '08:00', 'close_time' => '22:00'],
            'actual' => ['open_time' => $actual['open_time'] ?? null, 'close_time' => $actual['close_time'] ?? null],
        ];
    }
    if (($actual['operating_days'] ?? null) !== 'Monday-Friday') {
        $failures[] = [
            'case' => $case['name'],
            'key' => 'operating_days',
            'expected' => 'Monday-Friday',
            'actual' => $actual['operating_days'] ?? null,
        ];
    }
}

$summary = [
    'status' => $failures === [] ? 'PASS' : 'FAIL',
    'cases' => count($cases),
    'checks' => count($cases) * 6,
    'failures' => $failures,
];

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
if ($failures !== []) {
    exit(1);
}

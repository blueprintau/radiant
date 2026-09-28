<?php

declare(strict_types=1);

/**
 * Builds the Shields endpoint badge from a serialized coverage run.
 *
 * PHPUnit itself emits the HTML report (--coverage-html) and Clover XML
 * (--coverage-clover) during the test run; the one artifact it cannot
 * produce is the badge JSON, so that is all this script does.
 *
 * Consumes the `coverage.php` file produced by
 *
 *     phpunit --group default --group integration-remote-sql \
 *         --coverage-html coverage-report \
 *         --coverage-clover coverage-report/coverage.xml \
 *         --coverage-php coverage.php
 *
 * and writes `coverage-report/coverage-badge.json` (Shields endpoint
 * schema, served from Pages and read by the README badge).
 *
 * Usage: php ci/coverage-badge.php [coverage.php] [coverage-report]
 */

use SebastianBergmann\CodeCoverage\Report\Facade as ReportFacade;
use SebastianBergmann\CodeCoverage\Serialization\Unserializer;

require __DIR__ . '/../vendor/autoload.php';

$coverageFile = $argv[1] ?? __DIR__ . '/../coverage.php';
$reportDir = $argv[2] ?? __DIR__ . '/../coverage-report';

if (!is_file($coverageFile)) {
    fwrite(STDERR, "Coverage file not found: {$coverageFile}\n");
    exit(1);
}

if (!is_dir($reportDir)) {
    fwrite(STDERR, "Report directory not found (PHPUnit --coverage-html should have created it): {$reportDir}\n");
    exit(1);
}

// The serialized file is a PHP array payload (buildInformation, basePath,
// codeCoverage, testResults) — NOT a bare CodeCoverage object as in
// php-code-coverage 10. The official Unserializer + Report Facade path
// rebuilds the report tree just far enough to compute the summary.
$unserializer = new Unserializer();
$data = $unserializer->unserialize($coverageFile);
$facade = ReportFacade::fromSerializedData($data);

// Shields endpoint badge: {"schemaVersion":1,"label":"coverage",...}
$percentage = $facade->summary()->lineCoverageAsPercentage();
$color = match (true) {
    $percentage >= 90 => 'brightgreen',
    $percentage >= 75 => 'green',
    $percentage >= 50 => 'yellow',
    default => 'red',
};

file_put_contents($reportDir . '/coverage-badge.json', json_encode([
    'schemaVersion' => 1,
    'label' => 'coverage',
    'message' => sprintf('%.2f%%', $percentage),
    'color' => $color,
]));

printf("Coverage: %.2f%% lines -> %s/coverage-badge.json\n", $percentage, $reportDir);

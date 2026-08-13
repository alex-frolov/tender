<?php

declare(strict_types=1);

/**
 * Проверка порога покрытия кода (задача 7.8, AGENTS.md: PHPUnit ≥ 80%).
 *
 * Читает clover.xml (generated paratest/phpunit --coverage-clover) и считает
 * покрытие строк (coveredstatements/statements). Если ниже порога — exit 1
 * (CI красный), иначе — exit 0.
 *
 * Использование:
 *   php scripts/check-coverage.php <path/to/clover.xml> [threshold] [--json]
 *
 * Порог по умолчанию — 80 (%). Переопределение: COVERAGE_THRESHOLD env.
 */
if (\PHP_SAPI !== 'cli') {
    exit(2);
}

$args = array_slice($argv, 1);

$cloverFile = $args[0] ?? 'var/coverage/clover.xml';
$threshold = (float) ($args[1] ?? (getenv('COVERAGE_THRESHOLD') ?: 80));
$json = in_array('--json', $args, true);

if (!is_file($cloverFile)) {
    fwrite(\STDERR, "FAIL: coverage file not found: {$cloverFile}\n");
    exit(1);
}

$xml = @simplexml_load_file($cloverFile);
if (false === $xml) {
    fwrite(\STDERR, "FAIL: cannot parse clover XML: {$cloverFile}\n");
    exit(1);
}

$metrics = $xml->project->metrics ?? null;
if (null === $metrics) {
    fwrite(\STDERR, "FAIL: clover has no project metrics: {$cloverFile}\n");
    exit(1);
}

$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$coverage = 0 === $statements ? 0.0 : (100.0 * $covered / $statements);

$report = sprintf(
    'Coverage: %.2f%% lines (%d/%d), threshold %.1f%%',
    $coverage,
    $covered,
    $statements,
    $threshold,
);

if ($json) {
    echo json_encode([
        'file' => $cloverFile,
        'lines' => $coverage,
        'covered_statements' => $covered,
        'statements' => $statements,
        'threshold' => $threshold,
        'pass' => $coverage >= $threshold,
    ], \JSON_PRETTY_PRINT), "\n";
}

echo $report, "\n";

if ($coverage < $threshold) {
    fwrite(\STDERR, "FAIL: coverage below threshold\n");
    exit(1);
}

echo "OK: coverage meets threshold\n";
exit(0);

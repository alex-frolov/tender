<?php

declare(strict_types=1);

/**
 * Проверка реестра JSON Schema событий (schema registry,
 * testing-strategy.md §5 «CI: проверка обратной совместимости»).
 *
 * Гарантии, которые даёт скрипт (fail fast, exit != 0 на нарушение):
 *  1. Каждое событие из реестра событий имеет файл схемы
 *     (config/schemas/events/{event_type}.json) — покрытие реестра;
 *  2. Каждый файл схемы соответствует событию реестра (нет orphan-файлов);
 *  3. Каждая схема структурно корректна: валидный JSON, envelope (event_id/
 *     event_type/occurred_at/tenant_id/aggregate_type/aggregate_id/payload),
 *     event_type.const == имени файла, aggregate_type.const из известного набора,
 *     payload: object + additionalProperties=false (запрет необъявленных полей);
 *  4. Контракт envelope единообразен для всех событий (одинаковый required).
 *
 * Запуск:
 *   php scripts/check-event-schemas.php [repo_root]
 * (по умолчанию repo_root = текущая директория app/ — корень публикуемого репозитория).
 *
 * Требует только PHP без внешних зависимостей (json_decode), запускается в CI
 * отдельным шагом до PHPUnit (см. .github/workflows/ci.yml, composer schema:check).
 */

// Поиск корня репозитория: от каталога скрипта вверх до каталога схем событий
// (не зависит от рабочей директории — работает и в docker, и в CI).
// Принимаются обе раскладки: монорепо (tender/ + app/ — domain/events.md +
// app/config/schemas/events) и «app — корень публикуемого репозитория»
// (docs/events.md + config/schemas/events).
$dir = dirname(__DIR__);
$repoRoot = null;
$eventsFileRel = null;
while (true) {
    $appSchemas = $dir.'/app/config/schemas/events';
    $rootSchemas = $dir.'/config/schemas/events';
    if (is_dir($appSchemas) && is_file($dir.'/domain/events.md')) {
        $repoRoot = $dir;
        $eventsFileRel = 'domain/events.md';
        break;
    }
    if (is_dir($rootSchemas) && is_file($dir.'/docs/events.md')) {
        $repoRoot = $dir;
        $eventsFileRel = 'docs/events.md';
        break;
    }
    $parent = dirname($dir);
    if ($parent === $dir) {
        break;
    }
    $dir = $parent;
}

// Явный аргумент имеет приоритет (для CI, где монтируется только корень).
if (null !== ($argv[1] ?? null)) {
    $candidate = rtrim((string) $argv[1], '/');
    if (is_dir($candidate.'/config/schemas/events') && is_file($candidate.'/docs/events.md')) {
        $repoRoot = $candidate;
        $eventsFileRel = 'docs/events.md';
    } elseif (is_dir($candidate.'/app/config/schemas/events') && is_file($candidate.'/domain/events.md')) {
        $repoRoot = $candidate;
        $eventsFileRel = 'domain/events.md';
    }
}

if (null === $repoRoot) {
    fwrite(\STDERR, "Tender Platform repo root not found (need config/schemas/events + docs/events.md, or domain/events.md + app/config/schemas/events)\n");
    exit(1);
}

$schemasDir = is_dir($repoRoot.'/config/schemas/events') ? $repoRoot.'/config/schemas/events' : $repoRoot.'/app/config/schemas/events';
if (!is_dir($schemasDir)) {
    fwrite(\STDERR, "Schemas directory not found: {$schemasDir}\n");
    exit(1);
}

/** Известные типы агрегатов конверта (уточняются при появлении новых событий). */
$knownAggregates = [
    'tender', 'auction', 'bid', 'contract', 'claim', 'analytics',
    'export_job', 'webhook_delivery', 'platform', 'company', 'user',
];

$events = extractEventTypes($repoRoot.'/'.$eventsFileRel);
$schemaFiles = glob($schemasDir.'/*.json') ?: [];

$failures = [];
$count = 0;

// 1. Покрытие: каждое событие реестра имеет схему.
foreach ($events as $eventType) {
    if (!is_file($schemasDir.'/'.$eventType.'.json')) {
        $failures[] = "missing schema for event {$eventType} (declared in {$eventsFileRel})";
    }
}

// 2. Обратное покрытие: каждый файл схемы соответствует событию реестра.
foreach ($schemaFiles as $file) {
    $eventType = basename($file, '.json');
    if (!in_array($eventType, $events, true)) {
        $failures[] = "orphan schema {$eventType}.json (not declared in {$eventsFileRel})";
    }
}

// 3–4. Структурная валидация каждой схемы.
foreach ($schemaFiles as $file) {
    $eventType = basename($file, '.json');
    $decoded = json_decode((string) file_get_contents($file), false);
    if (!is_object($decoded)) {
        $failures[] = "{$eventType}.json is not a valid JSON object";
        continue;
    }

    $schema = $decoded;
    if (($schema->{'$schema'} ?? null) !== 'https://json-schema.org/draft/2020-12/schema') {
        $failures[] = "{$eventType}.json: \$schema must be draft/2020-12";
    }
    if (($schema->title ?? null) !== $eventType) {
        $failures[] = "{$eventType}.json: title must equal event type";
    }
    if (($schema->type ?? null) !== 'object') {
        $failures[] = "{$eventType}.json: root type must be object";
    }

    // Единый конверт (envelope).
    $envelope = ['event_id', 'event_type', 'occurred_at', 'tenant_id', 'aggregate_type', 'aggregate_id', 'payload'];
    $required = $schema->required ?? [];
    foreach ($envelope as $field) {
        if (!in_array($field, $required, true)) {
            $failures[] = "{$eventType}.json: envelope field {$field} must be required";
        }
    }

    $props = $schema->properties ?? new stdClass();
    $eventTypeConst = $props->event_type->const ?? null;
    if ($eventTypeConst !== $eventType) {
        $failures[] = "{$eventType}.json: event_type.const must equal '{$eventType}'";
    }

    $aggregateConst = $props->aggregate_type->const ?? null;
    if (!is_string($aggregateConst) || '' === $aggregateConst) {
        $failures[] = "{$eventType}.json: aggregate_type.const must be present";
    } elseif (!in_array($aggregateConst, $knownAggregates, true)) {
        $failures[] = "{$eventType}.json: unknown aggregate_type.const '{$aggregateConst}' (add to knownAggregates)";
    }

    $payload = $props->payload ?? new stdClass();
    if (($payload->type ?? null) !== 'object') {
        $failures[] = "{$eventType}.json: payload.type must be object";
    }
    if (false !== ($payload->additionalProperties ?? false)) {
        $failures[] = "{$eventType}.json: payload.additionalProperties must be false";
    }
    if (!is_object($payload->properties ?? null)) {
        $failures[] = "{$eventType}.json: payload.properties must be an object";
    }

    ++$count;
}

if ([] !== $failures) {
    fwrite(\STDERR, 'Schema registry check FAILED ('.count($failures)." violations):\n");
    foreach ($failures as $failure) {
        fwrite(\STDERR, "  - {$failure}\n");
    }
    exit(1);
}

echo 'Schema registry OK: '.count($events).' events in '.$eventsFileRel.', '.$count." schema files, envelope/payload contract valid.\n";
exit(0);

/**
 * Извлечение типов событий из реестра (domain/events.md или docs/events.md):
 * backtick-токены вида `prefix.action` (в т.ч. двух- и трёхсегментные:
 * platform.webhook.failed).
 *
 * @return list<string> уникальные типы событий в порядке появления
 */
function extractEventTypes(string $path): array
{
    $content = (string) file_get_contents($path);
    preg_match_all('/`([a-z][a-z0-9_]*(\.[a-z0-9_]+)+)`/', $content, $matches);

    $types = [];
    foreach ($matches[1] as $type) {
        if (!in_array($type, $types, true)) {
            $types[] = $type;
        }
    }

    return $types;
}

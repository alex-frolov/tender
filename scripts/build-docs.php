<?php

declare(strict_types=1);

/**
 * Сборка документации monorepo в один HTML-файл (P2, md → HTML/PDF-pipeline).
 *
 * Рендерит markdown-документы репозитория (README, architecture/, domain/,
 * operations/, schemas) в `docs/dist/index.html` — единый справочник с боковой
 * навигацией (GFM-таблицы поддерживаются). PDF — из браузера по готовому HTML
 * (Ctrl/Cmd+P → «Сохранить как PDF»), отдельный рендер в PDF не требуется.
 *
 * Запуск (докер-контейнер видит только app/, поэтому корень репо монтируется
 * отдельным volume; см. scripts/build-docs.sh):
 *   php /var/www/scripts/build-docs.php /repo
 *
 * Требует league/commonmark (require-dev).
 */

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

require __DIR__.'/../vendor/autoload.php';

if (!isset($argv[1])) {
    fwrite(\STDERR, "Usage: php build-docs.php <repo_root>\n");
    exit(1);
}

$repoRoot = rtrim((string) $argv[1], '/');
if (!is_dir($repoRoot.'/architecture') || !is_file($repoRoot.'/README.md')) {
    fwrite(\STDERR, "Not a Tender Platform repo root: {$repoRoot}\n");
    exit(1);
}

/**
 * Манифест документов: секция => [файл => подпись (null = из первого заголовка)].
 * Ключ — относительный путь; отсутствующие файлы отфильтровываются (null).
 *
 * @return array<string, array<string, string|null>>
 */
function manifest(string $root): array
{
    $md = static fn (string $file): ?string => is_file($root.'/'.$file) ? $file : null;

    return [
        'Обзор' => array_filter([
            'README.md' => 'README — обзор репозитория',
        ]),
        'Архитектура' => array_filter([
            'architecture/architecture-review.md' => $md('architecture/architecture-review.md'),
            'architecture/modular-monolith.md' => $md('architecture/modular-monolith.md'),
            'architecture/modules.md' => $md('architecture/modules.md'),
            'architecture/refactor-modular-monolith.md' => $md('architecture/refactor-modular-monolith.md'),
            'architecture/testing-strategy.md' => $md('architecture/testing-strategy.md'),
            'architecture/audit.md' => $md('architecture/audit.md'),
            'architecture/audit2.md' => $md('architecture/audit2.md'),
            'architecture/audit3.md' => $md('architecture/audit3.md'),
            'architecture/adr/ADR-001-005.md' => $md('architecture/adr/ADR-001-005.md'),
        ]),
        'Домен' => array_filter([
            'domain/data-model.md' => $md('domain/data-model.md'),
            'domain/events.md' => $md('domain/events.md'),
            'domain/permissions.md' => $md('domain/permissions.md'),
            'domain/role-matrix.md' => $md('domain/role-matrix.md'),
            'domain/auction-state-machine.md' => $md('domain/auction-state-machine.md'),
            'domain/tender-state-machine.md' => $md('domain/tender-state-machine.md'),
            'domain/contract-state-machine.md' => $md('domain/contract-state-machine.md'),
            'domain/company-state-machine.md' => $md('domain/company-state-machine.md'),
            'domain/use-cases.md' => $md('domain/use-cases.md'),
            'domain/plugins/ru-state-procurement.md' => $md('domain/plugins/ru-state-procurement.md'),
        ]),
        'Эксплуатация' => array_filter([
            'operations/deployment.md' => $md('operations/deployment.md'),
            'operations/observability.md' => $md('operations/observability.md'),
        ]),
        'Схемы событий' => array_filter([
            'app/config/schemas/events/README.md' => $md('app/config/schemas/events/README.md'),
        ]),
    ];
}

/**
 * Первый заголовок H1 файла (для подписи в навигации).
 */
function headingTitle(string $path): string
{
    $f = fopen($path, 'r');
    if (false === $f) {
        return basename($path);
    }
    while (false !== ($line = fgets($f))) {
        if (preg_match('/^#\s+(.+)$/', $line, $m)) {
            fclose($f);

            return trim((string) $m[1]);
        }
    }
    fclose($f);

    return basename($path);
}

$environment = new Environment(['html_input' => 'strip']);
$environment->addExtension(new CommonMarkCoreExtension());
$environment->addExtension(new GithubFlavoredMarkdownExtension());
$environment->addExtension(new TableExtension());
$environment->addExtension(new HeadingPermalinkExtension());
$converter = new MarkdownConverter($environment);

/**
 * @param array{title: string, body: string} $docs
 */
function renderHtml(array $docs, MarkdownConverter $converter): string
{
    $nav = '';
    $sections = '';
    $sectionIdx = 0;
    $docIdx = 0;
    foreach ($docs as $section => $items) {
        ++$sectionIdx;
        $nav .= '<div class="nav-section">'.htmlspecialchars((string) $section).'</div>';
        foreach ($items as $item) {
            $anchor = 'doc-'.$docIdx;
            $nav .= '<a class="nav-link" href="#'.$anchor.'">'.htmlspecialchars((string) $item['title']).'</a>';
            $sections .= '<section class="doc" id="'.$anchor.'">'.$item['body'].'</section>';
            ++$docIdx;
        }
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tender Platform — документация</title>
<style>
:root { color-scheme: light; }
* { box-sizing: border-box; }
body { margin: 0; font: 15px/1.6 -apple-system, "Segoe UI", Roboto, sans-serif; color: #1f2328; }
.layout { display: flex; min-height: 100vh; }
nav.sidebar { width: 300px; flex: 0 0 300px; overflow-y: auto; max-height: 100vh; position: sticky; top: 0; padding: 16px 12px; background: #f6f8fa; border-right: 1px solid #d0d7de; }
.nav-section { margin: 14px 4px 4px; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #656d76; font-weight: 600; }
a.nav-link { display: block; padding: 3px 8px; border-radius: 6px; color: #0969da; text-decoration: none; font-size: 13px; }
a.nav-link:hover { background: #eaeef2; }
main { flex: 1; padding: 32px 48px 80px; max-width: 960px; }
.doc { margin-bottom: 48px; }
h1, h2, h3, h4 { line-height: 1.3; margin-top: 1.5em; }
h1 { border-bottom: 1px solid #d8dee4; padding-bottom: .3em; font-size: 1.6em; }
h2 { border-bottom: 1px solid #eaeef2; padding-bottom: .2em; font-size: 1.3em; }
a.anchor-link { text-decoration: none; color: #0969da; opacity: 0; padding-left: .4em; }
h1:hover a.anchor-link, h2:hover a.anchor-link, h3:hover a.anchor-link, h4:hover a.anchor-link { opacity: 1; }
code { background: #f6f8fa; padding: .15em .4em; border-radius: 4px; font-size: 85%; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
pre { background: #f6f8fa; padding: 14px; border-radius: 8px; overflow-x: auto; border: 1px solid #eaeef2; }
pre code { background: none; padding: 0; font-size: 13px; }
blockquote { border-left: 4px solid #d0d7de; margin-left: 0; padding-left: 16px; color: #57606a; }
table { border-collapse: collapse; margin: 12px 0; width: 100%; display: block; overflow-x: auto; }
th, td { border: 1px solid #d8dee4; padding: 6px 10px; text-align: left; font-size: 13.5px; }
th { background: #f6f8fa; }
img { max-width: 100%; }
@media print { nav.sidebar { display: none; } main { max-width: none; padding: 0; } a { color: inherit; } }
</style>
</head>
<body>
<div class="layout">
<nav class="sidebar">{$nav}</nav>
<main>{$sections}</main>
</div>
</body>
</html>
HTML;
}

$docs = [];
foreach (manifest($repoRoot) as $section => $files) {
    foreach ($files as $rel => $label) {
        $abs = $repoRoot.'/'.$rel;
        $title = $label ?? headingTitle($abs);
        $markdown = (string) file_get_contents($abs);
        $body = (string) $converter->convert($markdown);
        $docs[$section][] = ['title' => $title, 'body' => $body];
    }
}

// Схемы событий: к README добавляем сами JSON-схемы (config/schemas/events/*.json).
$schemasDir = $repoRoot.'/app/config/schemas/events';
$schemaFiles = glob($schemasDir.'/*.json') ?: [];
sort($schemaFiles, \SORT_NATURAL);
foreach ($schemaFiles as $schemaFile) {
    $payload = file_get_contents($schemaFile);
    if (false === $payload) {
        continue;
    }
    $decoded = json_decode($payload, false);
    $pretty = false !== $decoded ? json_encode($decoded, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) : $payload;
    $docs['Схемы событий'][] = [
        'title' => 'schema: '.basename($schemaFile),
        'body' => '<pre><code>'.htmlspecialchars((string) $pretty).'</code></pre>',
    ];
}

$outDir = $repoRoot.'/docs/dist';
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}
file_put_contents($outDir.'/index.html', renderHtml($docs, $converter));

$docCount = array_sum(array_map(static fn (array $items): int => count($items), $docs));

echo 'docs/dist/index.html ('.$docCount.' документов)'.\PHP_EOL;

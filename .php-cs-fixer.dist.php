<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude('var')
    ->notPath([
        'config/bundles.php',
        'config/reference.php',
    ])
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        // strict_types — обязательное требование проекта (AGENTS.md): принудительно
        // добавляет declare(strict_types=1) во все PHP-файлы (переопределяет 'remove'
        // из @Symfony:risky).
        'declare_strict_types' => ['strategy' => 'enforce'],
        // PHPUnit-ассерты через self::assert* (в тестах уже так, приводим к единому стилю).
        'php_unit_test_case_static_method_calls' => ['call_type' => 'self'],
        // @var перед statement'ами НЕ превращать в /* */ — иначе PHPStan
        // (treatPhpDocTypesAsCertain: false) теряет тип переменной.
        'phpdoc_to_comment' => ['ignored_tags' => ['var']],
    ])
    ->setFinder($finder)
;

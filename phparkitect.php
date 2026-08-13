<?php

declare(strict_types=1);

use Arkitect\ClassSet;
use Arkitect\CLI\Config;
use Arkitect\Expression\ForClasses\NotDependsOnTheseNamespaces;
use Arkitect\Expression\ForClasses\NotHaveDependencyOutsideNamespace;
use Arkitect\Expression\ForClasses\ResideInOneOfTheseNamespaces;
use Arkitect\Rules\Rule;

/**
 * PHPArkitect: архитектурные правила Tender Platform.
 *
 * Слои и границы (ADR-001):
 * 1. Контроллеры не зависят от Infrastructure напрямую (только через сервисы).
 * 2. Сущности изолированы: модульные Entity (App\{Module}\Entity) и Shared-kernel
 *    (App\Shared\Entity) не зависят от контроллеров/инфраструктуры.
 * 3. Сквозной Money-модуль чистый (не зависит от других модулей и фреймворка).
 * 4. Infrastructure — нижний слой (никто не зависит от него, кроме его самого).
 * 5. Message-конверты изолированы от слоёв (кроме Entity-типов).
 * 6. Границы модулей: модуль не заглядывает во внутренности другого модуля;
 *    разрешены публичные контракты (корневые сервисы), App\Shared и enum как
 *    value-типы (App\{Module}\Entity\Enum).
 * 7. UseCase (application-слой модуля) не зависит от App\Controller (слой выше)
 *    и App\Infrastructure (слой ниже).
 */
$src = ClassSet::fromDir(__DIR__.'/src');

return static function (Config $config) use ($src): void {
    // --- 1. Контроллеры: не зависят от Infrastructure напрямую ---
    // App\Controller — кросс-срезовые (AbstractBaseController/Health);
    // App\{Module}\Controller — access-слой модулей внутри самих модулей.
    $config->add(
        $src,
        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces(
                'App\Controller',
                'App\Iam\Controller',
                'App\Tender\Controller',
                'App\Bid\Controller',
                'App\Auction\Controller',
                'App\Contract\Controller',
                'App\Document\Controller',
                'App\Platform\Controller',
                'App\Analytics\Controller',
                'App\Export\Controller',
                'App\Notification\Controller',
                'App\Favorite\Controller',
                'App\SavedSearch\Controller',
            ))
            ->should(new NotDependsOnTheseNamespaces([
                'App\Infrastructure',
            ]))
            ->because('контроллеры вызывают UseCase/сервисы, а не инфраструктуру напрямую (ADR-001, слои)'),
    );

    // --- 2. Сущности: изолированы от контроллеров и инфраструктуры ---
    // Правило покрывает модульные Entity (App\Iam\Entity, App\Tender\Entity,
    // App\Bid\Entity, App\Auction\Entity, App\Contract\Entity, App\Document\Entity),
    // Shared-kernel (App\Shared\Entity, включая App\Shared\Entity\Enum) и модульные
    // Enum (App\{Module}\Entity\Enum).
    // Сущности и value-типы — чистые модели данных: без App\Controller и
    // App\Infrastructure (Doctrine-атрибуты не считаются зависимостью от слоя).
    $config->add(
        $src,
        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces(
                'App\Shared\Entity',
                'App\Iam\Entity',
                'App\Tender\Entity',
                'App\Bid\Entity',
                'App\Auction\Entity',
                'App\Contract\Entity',
                'App\Document\Entity',
            ))
            ->should(new NotDependsOnTheseNamespaces([
                'App\Controller',
                'App\Infrastructure',
            ]))
            ->because('сущности изолированы от Controller/Infrastructure; правило покрывает модульные Entity + Shared kernel'),
    );

    // --- 3. Money: только PHP-стандарт, без фреймворка и других модулей ---
    $config->add(
        $src,
        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces('App\Shared\Money'))
            ->should(new NotHaveDependencyOutsideNamespace('App\Shared\Money'))
            ->because('Money-сервис — чистая доменная логика (PR-1..11), без Symfony/Doctrine/других модулей'),
    );

    // --- 4. Infrastructure: нижний слой, не зависит от контроллеров ---
    $config->add(
        $src,
        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces('App\Infrastructure'))
            ->should(new NotDependsOnTheseNamespaces([
                'App\Controller',
            ]))
            ->because('инфраструктура не зависит от верхних слоёв'),
    );

    // --- 5. Message: конверты событий изолированы от слоёв (кроме Entity-типов) ---
    // EventMessage (общий outbox-конверт) → Shared\Events; TimelineMessage
    // (отложенные задачи таймлайна тендера) → Tender\Timeline.
    $config->add(
        $src,
        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces(
                'App\Shared\Events\EventMessage',
                'App\Tender\Timeline\TimelineMessage',
            ))
            ->should(new NotDependsOnTheseNamespaces([
                'App\Controller',
                'App\Infrastructure',
            ]))
            ->because('сообщения — DTO для шины, без зависимостей от слоёв'),
    );

    // --- 6. Границы модулей (ADR-001, architecture/modules.md) ---
    // Модуль не заглядывает во внутренности другого модуля: Controller/
    // Command/Entity/Repository/Form/Input/Presenter/Exception/Storage/
    // Rules/State/Stream/Timer/Step/Timeline/Service. Публичные контракты
    // модулей (корневые сервисы, интерфейсы *ReadService, UseCase) остаются
    // разрешёнными — «module → публичный сервис/UseCase другого модуля»
    //
    // Подход «чёрный список + явный whitelist»: запрещены ВСЕ внутренности
    // (включая Service и нестандартные под-домены), а любой кросс-модульный
    // доступ к ним должен быть ЯВНО объявлен в $publicContractExcludes
    // (аналог $readModelExcludes, но для сервисов/инфраструктуры).
    $moduleNamespaces = [
        'App\Iam',
        'App\Tender',
        'App\Bid',
        'App\Auction',
        'App\Contract',
        'App\Document',
        'App\Platform',
        'App\Analytics',
        'App\Export',
        'App\Notification',
        'App\Favorite',
        'App\SavedSearch',
        'App\RuStateProcurement',
    ];
    $moduleInternals = ['Controller', 'Command', 'Entity', 'Repository', 'Form', 'Input', 'Presenter', 'Exception', 'Storage', 'Rules', 'State', 'Stream', 'Timer', 'Step', 'Timeline', 'Service'];
    // Явный whitelist публичных контрактов: кросс-модульные доступы к
    // внутренностям, объявленные осознанно. Ключ — модуль-потребитель,
    // значение — классы/интерфейсы модуля-владельца, разрешённые ему.
    // Техническая аутентификация (JWT/AuthMiddleware)
    // для Platform (ApiKeyAuthMiddleware) — whitelist.
    $publicContractExcludes = [
        'App\Platform' => [
            'App\Iam\Service\AuthContext',
            'App\Iam\Service\AuthMiddleware',
            'App\Iam\Service\JwtService',
            'App\Iam\Service\PermissionCheckerInterface',
        ],
        // Плагин ru-state-procurement (PL-1/PL-8, 7.7) реализует контракты правил
        // ядра (policy-плагин). Контракты живут во внутренних namespace'ах модулей
        // (Tender\Timeline, Auction\Rules, Contract\Rules) — whitelist на сами
        // интерфейсы + сущности как read-модели (см. $readModelExcludes).
        'App\RuStateProcurement' => [
            'App\Tender\Timeline\TimelineRules',
            'App\Auction\Rules\AuctionRules',
            'App\Contract\Rules\SecurityRules',
        ],
    ];
    // Read-модель / доменные связки модулей:
    // модуль принимает сущности другого модуля как read-модель
    // и/или управляет их жизненным циклом через workflow-механизмы модуля-владельца.
    // Остаётся:
    //   App\Bid → App\Tender\Entity (read-модель Tender/Lot для допуска
    //     участников BidService + BidOpeningService пишет tenders.bids_opened_at).
    //   App\RuStateProcurement → App\Tender\Entity / App\Auction\Entity (read-модели
    //     через контракты правил и публичные read-сервисы — плагин ru-state-procurement).
    $readModelExcludes = [
        'App\Bid' => ['App\Tender\Entity'],
        'App\RuStateProcurement' => ['App\Tender\Entity', 'App\Auction\Entity'],
    ];
    // Enum'ы — value-типы: хотя физически живут
    // в `App\{Module}\Entity\Enum`, кросс-модульное использование разрешено, как
    // для `App\Shared` (например Auction использует Tender\PriceBasisEnum,
    // Contract — Document\EntityType/DocumentScope). Классы Entity другого модуля
    // при этом остаются под запретом (разрешены только enum).
    $enumValueTypeNamespaces = array_map(
        static fn (string $m): string => $m.'\Entity\Enum',
        $moduleNamespaces
    );

    // Identity-сущности модуля Iam (User/Company/Permission/RolePermission/токены) —
    // кросс-срезовые read-модели: потребляются всеми модулями и Security-слоем
    // (currentUser, Voter'ы). Как и enum-ы, разрешены кросс-модульно аналогично
    // `App\Shared` (identity вынесен из Shared в Iam, но остаётся общим контрактом).
    // Это осознанное исключение: identity — сквозной контракт, а не внутренность Iam.
    $identityEntityNamespaces = [
        'App\Iam\Entity',
    ];

    foreach ($moduleNamespaces as $module) {
        $forbidden = [];
        foreach ($moduleNamespaces as $other) {
            if ($other === $module) {
                continue;
            }
            foreach ($moduleInternals as $internal) {
                $forbidden[] = $other.'\\'.$internal;
            }
        }

        $exclude = array_merge(
            $readModelExcludes[$module] ?? [],
            $publicContractExcludes[$module] ?? [],
            $enumValueTypeNamespaces,
            $identityEntityNamespaces,
        );

        $config->add(
            $src,
            Rule::allClasses()
                ->that(new ResideInOneOfTheseNamespaces($module))
                ->should(new NotDependsOnTheseNamespaces($forbidden, $exclude))
                ->because('граница модуля: '.$module.' не заглядывает во внутренности других модулей (ADR-001); только публичные контракты'),
        );
    }

    // --- 7. UseCase-слой: не зависит от Controller (выше) и Infrastructure (ниже) ---
    // Application-слой модуля (App\{Module}\UseCase) — публичный контракт модуля:
    // зависит только от своего модуля, App\Shared и
    // публичных контрактов других модулей. Слой выше (контроллеры) и ниже
    // (инфраструктура) — вне границ UseCase. Используем $moduleNamespaces (как в
    // правиле 6): UseCase-подпространство каждого модуля.
    $useCaseNamespaces = array_map(
        static fn (string $m): string => $m.'\UseCase',
        $moduleNamespaces
    );
    $config->add(
        $src,
        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces(...$useCaseNamespaces))
            ->should(new NotDependsOnTheseNamespaces([
                'App\Controller',
                'App\Infrastructure',
            ]))
            ->because('UseCase (application-слой) не зависит от Controller и Infrastructure (modular-monolith.md §3.3)'),
    );
};

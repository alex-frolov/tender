<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Controller\WelcomeController;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Корневая страница (welcome) — доступна без аутентификации,
 * отдаёт HTML (Twig) с переходом на /scalar, промо-блоком о платформе
 * и переключением локали (ru/en).
 *
 * Каждый запрос — с уникальным IP: глобальный rate-limit (RL-1) общий
 * на Redis, изоляция счётчиков как в RegistrationTest/RateLimitE2ETest.
 */
final class WelcomePageTest extends WebTestCase
{
    /**
     * Клиент с уникальным IP (изоляция rate-limit счётчиков).
     */
    private static function createClientWithIp(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = self::createClient();
        $client->setServerParameter('REMOTE_ADDR', '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254));

        return $client;
    }

    public function testRootReturnsWelcomePage(): void
    {
        $client = self::createClientWithIp();
        $client->request('GET', WelcomeController::URL);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');

        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Tender Platform', $content);
        // переход на API Reference
        self::assertStringContainsString('/scalar', $content);
    }

    public function testRootDoesNotRequireAuthentication(): void
    {
        $client = self::createClientWithIp();
        $client->request('GET', WelcomeController::URL);

        // без токена — 200 (welcome публичная)
        self::assertResponseIsSuccessful();
    }

    public function testPromoBlockAndAuthorPresent(): void
    {
        $client = self::createClientWithIp();
        $client->request('GET', WelcomeController::URL);

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        // промо-блок: технические характеристики (не хардкодим, ключи переведены)
        self::assertStringContainsString('OpenAPI 3.1', $content, 'промо-блок про API');
        // авторство + контакт
        self::assertStringContainsString('aleksander@frolov.guru', $content);
        // переключатель локали
        self::assertStringContainsString('RU', $content);
        self::assertStringContainsString('EN', $content);
    }

    public function testDefaultLocaleIsEnglish(): void
    {
        $client = self::createClientWithIp();
        $client->request('GET', WelcomeController::URL);

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        // EN-перевод по умолчанию (default_locale: en) — английский текст
        self::assertStringContainsString('Production-grade e-procurement engine', $content);
        self::assertStringContainsString('Open /scalar', $content);
    }

    public function testLocaleSwitchToRussian(): void
    {
        $client = self::createClientWithIp();
        $client->request('GET', WelcomeController::URL.'?_locale=ru');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        // русский вариант перевода
        self::assertStringContainsString('Промышленный движок электронных торгов', $content);
        self::assertStringContainsString('Открыть /scalar', $content);
    }
}

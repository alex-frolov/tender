<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Корневая страница приложения (welcome): посадочная страница проекта
 * с переходом на интерактивный API Reference (/scalar, scalar-symfony).
 *
 * До установки/без API на корне был 404 — теперь живой welcome-экран.
 * Рендер — Twig (templates/welcome.html.twig), переводы ru/en (домен "welcome",
 * default_locale: en). Параметр ?_locale=ru|en переключает локаль через
 * LocaleSwitcher (для ссылок-переключателей на странице); иначе — локаль
 * из запроса (Accept-Language), fallback en.
 */
final class WelcomeController extends AbstractController
{
    public const string URL = '/';

    public function __construct(private readonly LocaleSwitcher $localeSwitcher)
    {
    }

    #[Route(self::URL, name: 'app_welcome', methods: [Request::METHOD_GET])]
    public function __invoke(Request $request): Response
    {
        $locale = $request->query->get('_locale');
        if (\in_array($locale, ['ru', 'en'], true)) {
            return $this->localeSwitcher->runWithLocale($locale, fn (): Response => $this->render('welcome.html.twig'));
        }

        return $this->render('welcome.html.twig');
    }
}

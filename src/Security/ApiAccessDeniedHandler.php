<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Формат ответов доступа для JWT-API (FR-1.5.2).
 *
 * - не аутентифицирован (нет/невалидный токен) — 401 Unauthorized;
 * - аутентифицирован, но недостаточно прав — 403 Forbidden.
 * Формат совпадает с прежними ручными ответами контроллеров, чтобы не менять контракт
 * (см. api/openapi.yaml).
 */
final class ApiAccessDeniedHandler implements AccessDeniedHandlerInterface, AuthenticationEntryPointInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse(
            ['title' => 'Unauthorized', 'code' => 'invalid_credentials'],
            Response::HTTP_UNAUTHORIZED,
        );
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        return new JsonResponse(
            ['title' => 'Forbidden', 'code' => 'forbidden', 'detail' => $accessDeniedException->getMessage()],
            Response::HTTP_FORBIDDEN,
        );
    }
}

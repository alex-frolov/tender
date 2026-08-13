<?php

declare(strict_types=1);

namespace App\Iam\Controller\Auth;

use App\Controller\AbstractBaseController;
use App\Iam\Form\TokenType;
use App\Iam\Input\TokenInput;
use App\Iam\UseCase\TokenUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Получение JWT-токенов по credentials (+ опциональный TOTP) (FR-1.5.3).
 * Валидацию входных данных выполняет форма TokenType (422 при невалидных),
 * аутентификацию и выдачу токенов — TokenUseCase (прикладной слой модуля);
 * неверные credentials → 401 {title: Unauthorized, code: invalid_credentials}
 * (UseCaseResult).
 * Контракт: api/openapi.yaml (/auth/token).
 */
final class TokenController extends AbstractBaseController
{
    public const string URL = '/api/v1/auth/token';

    #[Route(self::URL, name: 'auth_token', methods: [Request::METHOD_POST])]
    public function token(Request $request, TokenUseCase $useCase): JsonResponse
    {
        $form = $this->formInput(TokenType::class, $request, strict: true);
        /** @var TokenInput $input */
        $input = $form->getData();

        $result = $useCase->execute(
            $input,
            $request->getClientIp(),
            (string) $request->headers->get('User-Agent', ''),
        );

        return $this->json($result->payload, $result->status, $result->headers);
    }
}

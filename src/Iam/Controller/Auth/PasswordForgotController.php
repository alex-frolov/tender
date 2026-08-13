<?php

declare(strict_types=1);

namespace App\Iam\Controller\Auth;

use App\Controller\AbstractBaseController;
use App\Iam\Form\PasswordForgotType;
use App\Iam\Input\ForgotPasswordInput;
use App\Iam\UseCase\ForgotPasswordUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Запрос восстановления пароля (FR-1.5.6, шаг 1).
 * Валидацию входных данных выполняет форма PasswordForgotType (422 при
 * невалидных), cooldown/отправку и статус ответа — ForgotPasswordUseCase
 * (429 при rate limit; существование email не раскрывается — 202 в любом
 * случае, кроме cooldown).
 * Контракт: api/openapi.yaml (/auth/password/forgot).
 */
final class PasswordForgotController extends AbstractBaseController
{
    public const string URL = '/api/v1/auth/password/forgot';

    #[Route(self::URL, name: 'auth_password_forgot', methods: [Request::METHOD_POST])]
    public function forgotPassword(Request $request, ForgotPasswordUseCase $useCase): JsonResponse
    {
        $form = $this->formInput(PasswordForgotType::class, $request, strict: true);
        /** @var ForgotPasswordInput $input */
        $input = $form->getData();

        $result = $useCase->execute($input, $request->getClientIp());

        return $this->json($result->payload, $result->status, $result->headers);
    }
}

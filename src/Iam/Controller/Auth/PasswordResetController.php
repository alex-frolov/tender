<?php

declare(strict_types=1);

namespace App\Iam\Controller\Auth;

use App\Controller\AbstractBaseController;
use App\Iam\Form\PasswordResetType;
use App\Iam\Input\ResetPasswordInput;
use App\Iam\UseCase\ResetPasswordUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Сброс пароля по одноразовому токену (FR-1.5.6, шаг 2).
 * Валидацию входных данных выполняет форма PasswordResetType (422 при
 * невалидных), проверку токена и статус ответа — ResetPasswordUseCase
 * (невалидный токен → 401 {code: invalid_reset_token}).
 * Контракт: api/openapi.yaml (/auth/password/reset).
 */
final class PasswordResetController extends AbstractBaseController
{
    public const string URL = '/api/v1/auth/password/reset';

    #[Route(self::URL, name: 'auth_password_reset', methods: [Request::METHOD_POST])]
    public function resetPassword(Request $request, ResetPasswordUseCase $useCase): JsonResponse
    {
        $form = $this->formInput(PasswordResetType::class, $request, strict: true);
        /** @var ResetPasswordInput $input */
        $input = $form->getData();

        $result = $useCase->execute($input);

        return $this->json($result->payload, $result->status, $result->headers);
    }
}

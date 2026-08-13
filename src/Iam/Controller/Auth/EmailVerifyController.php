<?php

declare(strict_types=1);

namespace App\Iam\Controller\Auth;

use App\Controller\AbstractBaseController;
use App\Iam\Form\EmailVerifyType;
use App\Iam\Input\VerifyEmailInput;
use App\Iam\UseCase\VerifyEmailUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Подтверждение email по токену (FR-1.5.5).
 * Валидацию входных данных выполняет форма EmailVerifyType (422 при
 * невалидных), проверку токена и статус ответа — VerifyEmailUseCase
 * (невалидный токен → 401 {code: invalid_verification_token}).
 * Контракт: api/openapi.yaml (/auth/email/verify).
 */
final class EmailVerifyController extends AbstractBaseController
{
    public const string URL = '/api/v1/auth/email/verify';

    #[Route(self::URL, name: 'auth_email_verify', methods: [Request::METHOD_POST])]
    public function verifyEmail(Request $request, VerifyEmailUseCase $useCase): JsonResponse
    {
        $form = $this->formInput(EmailVerifyType::class, $request, strict: true);
        /** @var VerifyEmailInput $input */
        $input = $form->getData();

        $result = $useCase->execute($input);

        return $this->json($result->payload, $result->status, $result->headers);
    }
}

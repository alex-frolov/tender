<?php

declare(strict_types=1);

namespace App\Iam\Controller\Auth;

use App\Controller\AbstractBaseController;
use App\Iam\Form\EmailResendType;
use App\Iam\Input\ResendEmailInput;
use App\Iam\UseCase\ResendEmailUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Повторная отправка письма подтверждения email (FR-1.5.5).
 * Валидацию входных данных выполняет форма EmailResendType (422 при
 * невалидных), cooldown/отправку и статус ответа — ResendEmailUseCase
 * (429 при rate limit).
 * Контракт: api/openapi.yaml (/auth/email/resend).
 */
final class EmailResendController extends AbstractBaseController
{
    public const string URL = '/api/v1/auth/email/resend';

    #[Route(self::URL, name: 'auth_email_resend', methods: [Request::METHOD_POST])]
    public function resendVerification(Request $request, ResendEmailUseCase $useCase): JsonResponse
    {
        $form = $this->formInput(EmailResendType::class, $request, strict: true);
        /** @var ResendEmailInput $input */
        $input = $form->getData();

        $result = $useCase->execute($input, $request->getClientIp());

        return $this->json($result->payload, $result->status, $result->headers);
    }
}

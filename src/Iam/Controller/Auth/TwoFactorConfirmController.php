<?php

declare(strict_types=1);

namespace App\Iam\Controller\Auth;

use App\Controller\AbstractBaseController;
use App\Iam\Form\TwoFactorConfirmType;
use App\Iam\Input\TwoFactorConfirmInput;
use App\Iam\UseCase\TwoFactorConfirmUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Подтверждение включения 2FA по TOTP-коду (FR-1.5.3).
 * Валидацию входных данных выполняет форма TwoFactorConfirmType (422 при
 * невалидных), проверку кода — TwoFactorConfirmUseCase (ValidationException
 * → 422).
 * Контракт: api/openapi.yaml (/auth/2fa/confirm).
 */
final class TwoFactorConfirmController extends AbstractBaseController
{
    public const string URL = '/api/v1/auth/2fa/confirm';

    #[Route(self::URL, name: 'auth_2fa_confirm', methods: [Request::METHOD_POST])]
    public function twoFactorConfirm(Request $request, TwoFactorConfirmUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $form = $this->formInput(TwoFactorConfirmType::class, $request, strict: true);
        /** @var TwoFactorConfirmInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute($user, $input));
    }
}

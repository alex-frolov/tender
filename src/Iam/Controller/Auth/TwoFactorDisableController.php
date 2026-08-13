<?php

declare(strict_types=1);

namespace App\Iam\Controller\Auth;

use App\Controller\AbstractBaseController;
use App\Iam\Form\TwoFactorDisableType;
use App\Iam\Input\TwoFactorDisableInput;
use App\Iam\UseCase\TwoFactorDisableUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Отключение 2FA по TOTP-коду (FR-1.5.3).
 * Валидацию входных данных выполняет форма TwoFactorDisableType (422 при
 * невалидных), проверку кода — TwoFactorDisableUseCase (ValidationException
 * → 422).
 * Контракт: api/openapi.yaml (/auth/2fa/disable).
 */
final class TwoFactorDisableController extends AbstractBaseController
{
    public const string URL = '/api/v1/auth/2fa/disable';

    #[Route(self::URL, name: 'auth_2fa_disable', methods: [Request::METHOD_POST])]
    public function twoFactorDisable(Request $request, TwoFactorDisableUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $form = $this->formInput(TwoFactorDisableType::class, $request, strict: true);
        /** @var TwoFactorDisableInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute($user, $input));
    }
}

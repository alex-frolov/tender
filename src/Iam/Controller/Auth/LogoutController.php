<?php

declare(strict_types=1);

namespace App\Iam\Controller\Auth;

use App\Controller\AbstractBaseController;
use App\Iam\Form\LogoutType;
use App\Iam\Input\LogoutInput;
use App\Iam\UseCase\LogoutUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Отзыв refresh-токена (FR-1.5.3).
 * Валидацию входных данных выполняет форма LogoutType (422 при невалидных);
 * повторный logout идемпотентен (200) — обработка в LogoutUseCase.
 * Контракт: api/openapi.yaml (/auth/logout).
 */
final class LogoutController extends AbstractBaseController
{
    public const string URL = '/api/v1/auth/logout';

    #[Route(self::URL, name: 'auth_logout', methods: [Request::METHOD_POST])]
    public function logout(Request $request, LogoutUseCase $useCase): JsonResponse
    {
        $form = $this->formInput(LogoutType::class, $request, strict: true);
        /** @var LogoutInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute($input));
    }
}

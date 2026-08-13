<?php

declare(strict_types=1);

namespace App\Iam\Controller\Auth;

use App\Controller\AbstractBaseController;
use App\Iam\Form\RefreshType;
use App\Iam\Input\RefreshInput;
use App\Iam\UseCase\RefreshUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ротация refresh-токена (FR-1.5.3).
 * Валидацию входных данных выполняет форма RefreshType (422 при невалидных),
 * ротацию — RefreshUseCase (невалидный токен → 401 {code: invalid_credentials}).
 * Контракт: api/openapi.yaml (/auth/refresh).
 */
final class RefreshController extends AbstractBaseController
{
    public const string URL = '/api/v1/auth/refresh';

    #[Route(self::URL, name: 'auth_refresh', methods: [Request::METHOD_POST])]
    public function refresh(Request $request, RefreshUseCase $useCase): JsonResponse
    {
        $form = $this->formInput(RefreshType::class, $request, strict: true);
        /** @var RefreshInput $input */
        $input = $form->getData();

        $result = $useCase->execute(
            $input,
            $request->getClientIp(),
            (string) $request->headers->get('User-Agent', ''),
        );

        return $this->json($result->payload, $result->status, $result->headers);
    }
}

<?php

declare(strict_types=1);

namespace App\Iam\Controller\User;

use App\Controller\AbstractBaseController;
use App\Iam\Form\UpdateMeType;
use App\Iam\Input\UpdateMeInput;
use App\Iam\UseCase\UpdateMeUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Обновление своего профиля (FR-1.5.8, PATCH /users/me).
 * Доступен любому аутентифицированному пользователю. Валидацию входных данных
 * выполняет форма UpdateMeType (422 при невалидных), оркестрацию —
 * UpdateMeUseCase (проверка current_password, revoke refresh-токенов).
 * Контракт: api/openapi.yaml (/users/me PATCH).
 */
final class UpdateMeController extends AbstractBaseController
{
    public const string URL = '/api/v1/users/me';

    #[Route(self::URL, name: 'user_me_update', methods: [Request::METHOD_PATCH])]
    public function update(Request $request, UpdateMeUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $form = $this->formInput(UpdateMeType::class, $request);
        /** @var UpdateMeInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute(
            user: $user,
            input: $input,
            ip: $request->getClientIp(),
        ));
    }
}

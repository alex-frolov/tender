<?php

declare(strict_types=1);

namespace App\Iam\Controller\User;

use App\Controller\AbstractBaseController;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Form\UserUpdateType;
use App\Iam\Input\UpdateUserInput;
use App\Iam\UseCase\UpdateUserUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Обновление пользователя компании админом: смена роли и/или статуса (FR-1.5.8).
 * Валидацию входных данных выполняет форма UserUpdateType (422 при невалидных),
 * оркестрацию — UpdateUserUseCase (прикладной слой модуля); ошибки (ApiException)
 * в JSON превращает JsonApiExceptionSubscriber.
 * Контракт: api/openapi.yaml (/users/{userId} PATCH).
 */
final class UserUpdateController extends AbstractBaseController
{
    public const string URL = '/api/v1/users/{userId}';

    #[Route(self::URL, name: 'user_update', methods: [Request::METHOD_PATCH])]
    #[IsGranted(UserRoleEnum::ADMIN->value)]
    public function update(Request $request, string $userId, UpdateUserUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $form = $this->formInput(UserUpdateType::class, $request);
        /** @var UpdateUserInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute(
            user: $user,
            userId: $userId,
            input: $input,
            ip: $request->getClientIp(),
        ));
    }
}

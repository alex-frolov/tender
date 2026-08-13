<?php

declare(strict_types=1);

namespace App\Iam\Controller\User;

use App\Controller\AbstractBaseController;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\UseCase\DeleteUserUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Мягкое удаление пользователя с маскированием email (FR-1.5.9). Только admin.
 * Нельзя удалить последнего активного администратора (FR-1.5.8).
 * Оркестрацию выполняет DeleteUserUseCase (прикладной слой модуля); ошибки
 * (ApiException) в JSON превращает JsonApiExceptionSubscriber.
 * Контракт: api/openapi.yaml (/users/{userId} DELETE).
 */
final class UserDeleteController extends AbstractBaseController
{
    public const string URL = '/api/v1/users/{userId}';

    #[Route(self::URL, name: 'user_delete', methods: [Request::METHOD_DELETE])]
    #[IsGranted(UserRoleEnum::ADMIN->value)]
    public function delete(Request $request, string $userId, DeleteUserUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $useCase->execute(user: $user, userId: $userId, ip: $request->getClientIp());

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}

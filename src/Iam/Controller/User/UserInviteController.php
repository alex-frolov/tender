<?php

declare(strict_types=1);

namespace App\Iam\Controller\User;

use App\Controller\AbstractBaseController;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Form\UserInviteType;
use App\Iam\Input\InviteUserInput;
use App\Iam\UseCase\InviteUserUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Приглашение сотрудника (FR-1.5.8). Только admin (атрибут доступа, см. AGENTS.md).
 * Создаёт пользователя со статусом invited, отправляет письмо-приглашение.
 * Валидацию входных данных выполняет форма UserInviteType (422 при невалидных),
 * оркестрацию — InviteUserUseCase (прикладной слой модуля); ошибки (ApiException)
 * в JSON превращает JsonApiExceptionSubscriber.
 * Контракт: api/openapi.yaml (/users POST).
 */
final class UserInviteController extends AbstractBaseController
{
    public const string URL = '/api/v1/users';

    #[Route(self::URL, name: 'user_invite', methods: [Request::METHOD_POST])]
    #[IsGranted(UserRoleEnum::ADMIN->value)]
    public function invite(Request $request, InviteUserUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $form = $this->formInput(UserInviteType::class, $request);
        /** @var InviteUserInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute(
            user: $user,
            input: $input,
            ip: $request->getClientIp(),
        ), Response::HTTP_CREATED);
    }
}

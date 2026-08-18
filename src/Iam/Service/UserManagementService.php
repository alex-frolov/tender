<?php

declare(strict_types=1);

namespace App\Iam\Service;

use App\Iam\Entity\Enum\LocaleEnum;
use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\Enum\UserStatusEnum;
use App\Iam\Entity\Enum\UserStatusTransition;
use App\Iam\Entity\User;
use App\Iam\Exception\LastAdminException;
use App\Iam\Exception\UserNotFoundException;
use App\Iam\Input\InviteUserInput;
use App\Iam\Input\UpdateUserInput;
use App\Shared\Audit\AuditService;
use App\Shared\Exception\ConflictException;
use App\Shared\Exception\StateTransitionException;
use App\Shared\Exception\ValidationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Управление пользователями компании (FR-1.5.8, FR-1.5.9).
 *
 * Только admin своей компании: приглашение сотрудника (статус invited),
 * смена роли, блокировка/разблокировка, soft-delete с маскированием email.
 * Инварианты:
 *  - нельзя удалить/понизить последнего активного администратора компании (409);
 *  - soft-delete: deleted_at + email заменяется на u_{uuid}@deleted.local,
 *    логин под удалённым email невозможен, данные сохраняются (FR-1.5.9);
 *  - каждая мутация пишет append-only запись в аудит.
 *
 * Валидация входных данных (обязательность полей, формат email, роль/статус)
 * выполняется формами UserInviteType/UserUpdateType (в контроллере), разбор
 * userId и бизнес-правила — ЗДЕСЬ. Ошибки бросаются как ApiException
 * (ValidationException/ConflictException/UserNotFoundException/
 * LastAdminException) и единообразно превращаются в JSON-ответ подписчиком
 * JsonApiExceptionSubscriber — контроллеры остаются тонкими.
 */
final readonly class UserManagementService
{
    public function __construct(
        private EntityManagerInterface $em,
        private AuditService $audit,
        private RefreshTokenService $refreshTokens,
        private MailerInterface $mailer,
        private Environment $twig,
        private TranslatorInterface $translator,
        #[Autowire(service: 'state_machine.user_status')]
        private WorkflowInterface $userWorkflow,
        private string $inviteUrlTemplate,
        private string $from,
    ) {
    }

    /**
     * Приглашение сотрудника: создание пользователя со статусом invited (FR-1.5.8).
     * Роль по умолчанию — agent. На почту уходит письмо-приглашение.
     * Валидация входных данных — в форме UserInviteType.
     *
     * @throws ConflictException если email уже занят в компании
     */
    public function invite(User $actor, InviteUserInput $input, ?string $ip = null): User
    {
        $companyId = $this->requireCompany($actor);
        $email = strtolower(trim($input->email));
        $role = $this->resolveRole($input->role);

        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null !== $existing) {
            throw new ConflictException('User with this email already exists');
        }

        $user = new User($email, $input->name, $role, $companyId, LocaleEnum::RU);
        $user->setVerificationStatus(UserStatusEnum::INVITED);
        $this->em->persist($user);
        $this->em->flush();

        $this->sendInviteEmail($user);
        $this->audit->record(
            action: 'user.invited',
            entityType: 'user',
            entityId: (string) $user->getId(),
            tenantId: (string) $companyId,
            actorType: 'user',
            actorId: (string) $actor->getId(),
            after: ['role' => $role->value, 'status' => UserStatusEnum::INVITED->value],
            ip: $ip,
        );

        return $user;
    }

    /**
     * Список пользователей компании (FR-1.5.8).
     *
     * @return list<User>
     */
    public function listUsers(User $actor): array
    {
        $companyId = $this->requireCompany($actor);

        /** @var list<User> $users */
        $users = $this->em->getRepository(User::class)
            ->createQueryBuilder('u')
            ->where('u.companyId = :companyId')
            ->andWhere('u.verificationStatus <> :deleted')
            ->setParameter('companyId', $companyId)
            ->setParameter('deleted', UserStatusEnum::DELETED->value)
            ->orderBy('u.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $users;
    }

    /**
     * Обновление пользователя компании админом: смена роли и/или статуса (FR-1.5.8).
     * Оба поля опциональны — применяются только указанные. Нельзя понизить последнего
     * активного администратора. Валидация входных данных — в форме UserUpdateType.
     *
     * @throws LastAdminException    если это последний активный админ
     * @throws UserNotFoundException если пользователь не в компании актора
     */
    public function update(User $actor, string $userId, UpdateUserInput $input, ?string $ip = null): User
    {
        $companyId = $this->requireCompany($actor);
        $user = $this->resolveUser($companyId, $userId);

        if (null !== $input->name && '' !== $input->name && $input->name !== $user->getName()) {
            $this->rename($actor, $companyId, $user, $input->name, $ip);
        }

        if (null !== $input->role && '' !== $input->role) {
            $this->changeRole($actor, $companyId, $user, $this->resolveRole($input->role), $ip);
        }

        if (null !== $input->status && '' !== $input->status) {
            $statusEnum = UserStatusEnum::tryFrom($input->status);
            if (null === $statusEnum || (UserStatusEnum::ACTIVE !== $statusEnum && UserStatusEnum::BLOCKED !== $statusEnum)) {
                throw new ValidationException('status must be active or blocked');
            }
            $this->setStatus($actor, $companyId, $user, $statusEnum, $ip);
        }

        return $user;
    }

    /**
     * Смена имени пользователя админом (FR-1.5.8, PATCH /users/{userId}).
     */
    private function rename(User $actor, Uuid $companyId, User $user, string $name, ?string $ip): void
    {
        $before = $user->getName();
        $user->changeName($name);
        $this->em->flush();

        $this->audit->record(
            action: 'user.renamed',
            entityType: 'user',
            entityId: (string) $user->getId(),
            tenantId: (string) $companyId,
            actorType: 'user',
            actorId: (string) $actor->getId(),
            before: ['name' => $before],
            after: ['name' => $name],
            ip: $ip,
        );
    }

    /**
     * Смена роли пользователя компании (FR-1.5.8).
     * Нельзя понизить последнего активного администратора.
     *
     * @throws LastAdminException если это последний активный админ
     */
    private function changeRole(User $actor, Uuid $companyId, User $user, UserRoleEnum $role, ?string $ip): void
    {
        if (!$user->getRole()->isAdmin() && UserRoleEnum::ADMIN === $role) {
            // повышение допустимо всегда; ограничение — только на понижение
        } elseif ($user->getRole()->isAdmin() && UserRoleEnum::ADMIN !== $role && $this->isLastActiveAdmin($companyId)) {
            throw new LastAdminException('Cannot demote the last active administrator');
        }

        $before = $user->getRole();
        $user->changeRole($role);
        $this->em->flush();

        $this->audit->record(
            action: 'user.role_changed',
            entityType: 'user',
            entityId: (string) $user->getId(),
            tenantId: (string) $companyId,
            actorType: 'user',
            actorId: (string) $actor->getId(),
            before: ['role' => $before->value],
            after: ['role' => $role->value],
            ip: $ip,
        );
    }

    /**
     * Блокировка/разблокировка пользователя (FR-1.5.8).
     * Допустимые статусы: active / blocked. При блокировке сессии отзываются.
     */
    private function setStatus(User $actor, Uuid $companyId, User $user, UserStatusEnum $status, ?string $ip): void
    {
        $before = $user->getVerificationStatus();

        // Статус меняется только через workflow user_status (active → blocked → active).
        $transition = UserStatusEnum::BLOCKED === $status
            ? UserStatusTransition::BLOCK->value
            : UserStatusTransition::UNBLOCK->value;
        if (!$this->userWorkflow->can($user, $transition)) {
            throw new StateTransitionException(\sprintf('Cannot %s user from status %s', $transition, $before->value));
        }
        $this->userWorkflow->apply($user, $transition);
        $this->em->flush();

        if (UserStatusEnum::BLOCKED === $status) {
            $this->refreshTokens->revokeAllForUser($user->getId());
        }

        $this->audit->record(
            action: UserStatusEnum::BLOCKED === $status ? 'user.blocked' : 'user.unblocked',
            entityType: 'user',
            entityId: (string) $user->getId(),
            tenantId: (string) $companyId,
            actorType: 'user',
            actorId: (string) $actor->getId(),
            before: ['status' => $before->value],
            after: ['status' => $status->value],
            ip: $ip,
        );
    }

    /**
     * Мягкое удаление пользователя с маскированием email (FR-1.5.9).
     * Статус → deleted через workflow (терминальное состояние); повторное
     * удаление невозможно (переход delete из deleted отсутствует).
     * Администратора нельзя удалить, если он — последний активный админ.
     *
     * @throws LastAdminException    если это последний активный админ
     * @throws UserNotFoundException если пользователь не в компании актора
     */
    public function softDelete(User $actor, string $userId, ?string $ip = null): void
    {
        $companyId = $this->requireCompany($actor);
        $user = $this->resolveUser($companyId, $userId);

        if ($user->getRole()->isAdmin() && $this->isLastActiveAdmin($companyId)) {
            throw new LastAdminException('Cannot delete the last active administrator');
        }

        $beforeStatus = $user->getVerificationStatus();
        if (!$this->userWorkflow->can($user, UserStatusTransition::DELETE->value)) {
            throw new StateTransitionException(\sprintf('Cannot delete user from status %s', $beforeStatus->value));
        }
        $this->userWorkflow->apply($user, UserStatusTransition::DELETE->value);

        $beforeEmail = $user->getEmail();
        $user->softDelete();
        $this->refreshTokens->revokeAllForUser($user->getId());
        $this->em->flush();

        $this->audit->record(
            action: 'user.deleted',
            entityType: 'user',
            entityId: (string) $user->getId(),
            tenantId: (string) $companyId,
            actorType: 'user',
            actorId: (string) $actor->getId(),
            before: ['email' => $beforeEmail, 'status' => $beforeStatus->value],
            after: ['status' => UserStatusEnum::DELETED->value, 'deleted_at' => $user->getDeletedAt()?->format('Y-m-d\TH:i:s\Z'), 'email_masked' => true],
            ip: $ip,
        );
    }

    /**
     * Есть ли в компании только один активный администратор (включая удаляемого).
     */
    private function isLastActiveAdmin(Uuid $companyId): bool
    {
        $count = $this->em->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(User::class, 'u')
            ->where('u.companyId = :companyId')
            ->andWhere('u.role = :role')
            ->andWhere('u.verificationStatus = :status')
            ->andWhere('u.verificationStatus <> :deleted')
            ->setParameter('companyId', $companyId)
            ->setParameter('role', UserRoleEnum::ADMIN->value)
            ->setParameter('status', UserStatusEnum::ACTIVE->value)
            ->setParameter('deleted', UserStatusEnum::DELETED->value)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count <= 1;
    }

    /**
     * Найти пользователя в указанной компании (не удалённого).
     *
     * @throws UserNotFoundException
     */
    private function findInCompany(Uuid $companyId, Uuid $userId): User
    {
        /** @var User|null $user */
        $user = $this->em->getRepository(User::class)
            ->createQueryBuilder('u')
            ->where('u.id = :id')
            ->andWhere('u.companyId = :companyId')
            ->andWhere('u.verificationStatus <> :deleted')
            ->setParameter('id', $userId)
            ->setParameter('companyId', $companyId)
            ->setParameter('deleted', UserStatusEnum::DELETED->value)
            ->getQuery()
            ->getOneOrNullResult();

        if (null === $user) {
            throw new UserNotFoundException('User not found');
        }

        return $user;
    }

    /**
     * Найти пользователя в указанной компании (не удалённого) по строковому id.
     * Невалидный UUID трактуется как «не найден» (404).
     *
     * @throws UserNotFoundException
     */
    private function resolveUser(Uuid $companyId, string $userId): User
    {
        if (!Uuid::isValid($userId)) {
            throw new UserNotFoundException('User not found');
        }

        return $this->findInCompany($companyId, Uuid::fromString($userId));
    }

    /**
     * Разобрать роль из строки и проверить её допустимость.
     * platform_admin нельзя назначать через API компании. Пустое значение
     * (поле не указано) → по умолчанию agent.
     *
     * @throws ValidationException
     */
    private function resolveRole(?string $role): UserRoleEnum
    {
        if (null === $role || '' === $role) {
            return UserRoleEnum::AGENT;
        }

        $roleEnum = UserRoleEnum::tryFrom($role);
        if (null === $roleEnum || UserRoleEnum::PLATFORM_ADMIN === $roleEnum) {
            throw new ValidationException('role must be admin, manager or agent');
        }

        return $roleEnum;
    }

    /**
     * @return Uuid идентификатор компании актора (admin обязан быть в компании)
     *
     * @throws ConflictException если актор без компании
     */
    private function requireCompany(User $actor): Uuid
    {
        $companyId = $actor->getCompanyId();
        if (null === $companyId) {
            throw new ConflictException('Actor has no company');
        }

        return $companyId;
    }

    private function sendInviteEmail(User $user): void
    {
        $locale = $user->getLocale()->value;
        $email = (new Email())
            ->from($this->from)
            ->to($user->getEmail())
            ->subject($this->translator->trans('subject', [], 'invite.subject', $locale))
            ->text($this->twig->render('email/invite.txt.twig', [
                'link' => $this->inviteUrlTemplate,
                'locale' => $locale,
            ]));

        $this->mailer->send($email);
    }
}

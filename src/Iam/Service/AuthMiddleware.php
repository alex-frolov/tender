<?php

declare(strict_types=1);

namespace App\Iam\Service;

use App\Iam\Entity\Enum\UserStatusEnum;
use App\Iam\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

/**
 * Аутентификация по Bearer JWT (FR-1.5.3).
 *
 * - kernel.request (приоритет 90 — после rate limit 100);
 * - извлекает Authorization: Bearer <jwt>, валидирует подпись/срок;
 * - проверяет, что пользователь существует и не удалён/заблокирован;
 * - кладёт AuthContext в request attributes: _auth, _auth_user_id, ...
 * - публичные маршруты (/auth/*, /health, /api/doc) пропускаются.
 */
final class AuthMiddleware implements EventSubscriberInterface
{
    public const string ATTR_AUTH = '_auth';
    public const string ATTR_USER = '_auth_user';

    /** @var list<string> пути, не требующие аутентификации (auth: вход/регистрация/восстановление) */
    private const array PUBLIC_PREFIXES = [
        '/api/v1/auth/token',
        '/api/v1/auth/refresh',
        '/api/v1/auth/logout',
        '/api/v1/auth/register',
        '/api/v1/auth/email/',
        '/api/v1/auth/password/',
        '/health',
        '/api/doc',
    ];

    public function __construct(
        private readonly JwtService $jwt,
        private readonly EntityManagerInterface $em,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 90],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($this->isPublic($request->getPathInfo())) {
            return;
        }

        $authorization = $request->headers->get('Authorization', '');
        if (!\is_string($authorization) || !str_starts_with($authorization, 'Bearer ')) {
            return; // без токена — аноним; доступ решает контроллер/проверка прав
        }

        $token = $this->jwt->parse(substr($authorization, 7));
        if (null === $token) {
            return; // невалидный токен трактуется как аноним; 401 выдаёт контроллер
        }

        $claims = $this->jwt->claims($token);
        $userId = $claims['user_id'];
        if (!Uuid::isValid($userId)) {
            return;
        }

        $user = $this->em->getRepository(User::class)->find(Uuid::fromString($userId));
        if (null === $user
            || $user->isDeleted()
            || UserStatusEnum::BLOCKED === $user->getVerificationStatus()
            || UserStatusEnum::EMAIL_PENDING === $user->getVerificationStatus()
            || UserStatusEnum::INVITED === $user->getVerificationStatus()) {
            return; // пользователь не может действовать — контекст не создаём
        }

        $request->attributes->set(self::ATTR_USER, $user);
        $request->attributes->set(self::ATTR_AUTH, new AuthContext(
            userId: $user->getId(),
            companyId: $user->getCompanyId(),
            role: $user->getRole()->value,
            jti: $claims['jti'],
        ));

        // Авторизация (Voter'ы, #[IsGranted]) читает пользователя из security-токена.
        // Кладём authenticated-токен в TokenStorage, чтобы AccessDecisionManager
        // видел действующего пользователя (FR-1.5.2, механизм доступа — AGENTS.md).
        $this->tokenStorage->setToken(new UsernamePasswordToken(
            $user,
            'api',
            [$user->getRole()->value],
        ));
    }

    private function isPublic(string $path): bool
    {
        foreach (self::PUBLIC_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace App\Platform\Service;

use App\Iam\Entity\Enum\UserStatusEnum;
use App\Iam\Entity\User;
use App\Iam\Service\AuthContext;
use App\Iam\Service\AuthMiddleware;
use App\Iam\Service\JwtService;
use App\Platform\ApiKeyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Аутентификация по API-ключу (AR-3, AM-1).
 *
 * - kernel.request (приоритет 95 — после rate limit 100, до JWT AuthMiddleware 90);
 * - источник токена: заголовок X-API-Key (openapi apiKeyAuth) или
 *   Authorization: Bearer <key> (openapi bearerAuth: «OAuth2 access token /
 *   PAT / API-ключ (Bearer)»). Если Bearer — валидный JWT, его обрабатывает
 *   AuthMiddleware (JWT приоритетнее);
 * - lookup по SHA-256 хэшу токена (ApiKeyService::resolve): невалидный,
 *   отозванный или просроченный ключ трактуется как аноним (401 отдаёт
 *   security-компонент);
 * - аутентифицированный ключ действует ОТ ИМЕНИ пользователя-владельца (PAT):
 *   кладёт User в request attributes + AuthContext (companyId = tenant ключа)
 *   и authenticated-токен в TokenStorage, чтобы Voter'ы/`#[IsGranted]` видели
 *   пользователя. Права при этом сужаются scopes ключа (ScopedPermissionChecker);
 * - фиксирует last_used_at (ApiKeyService::recordLastUsed, best-effort).
 */
final class ApiKeyAuthMiddleware implements EventSubscriberInterface
{
    public const string ATTR_KEY = '_auth_api_key';

    /** @var list<string> пути, не требующие аутентификации (совпадает с AuthMiddleware) */
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
        private readonly ApiKeyService $keys,
        private readonly JwtService $jwt,
        private readonly EntityManagerInterface $em,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 95],
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

        $token = $this->extractToken($request);
        if (null === $token) {
            return; // без ключа — аноним; доступ решает контроллер/проверка прав
        }
        if ('' === $token) {
            return;
        }

        $key = $this->keys->resolve($token);
        if (null === $key) {
            return; // невалидный/отозванный/просроченный ключ — аноним; 401 отдаёт security
        }

        $user = $this->em->getRepository(User::class)->find($key->getUserId());
        if (null === $user
            || $user->isDeleted()
            || UserStatusEnum::BLOCKED === $user->getVerificationStatus()
            || UserStatusEnum::EMAIL_PENDING === $user->getVerificationStatus()
            || UserStatusEnum::INVITED === $user->getVerificationStatus()) {
            return; // владелец не может действовать — контекст не создаём
        }

        $this->keys->recordLastUsed($key);

        $request->attributes->set(self::ATTR_KEY, $key);
        $request->attributes->set(AuthMiddleware::ATTR_USER, $user);
        $request->attributes->set(AuthMiddleware::ATTR_AUTH, new AuthContext(
            userId: $user->getId(),
            companyId: $key->getTenantId(),
            role: $user->getRole()->value,
            jti: null,
        ));

        $this->tokenStorage->setToken(new UsernamePasswordToken(
            $user,
            'api',
            [$user->getRole()->value],
        ));
    }

    /**
     * Извлечение raw-токена: Bearer (JWT приоритетнее — его обработает
     * AuthMiddleware) → X-API-Key.
     */
    private function extractToken(Request $request): ?string
    {
        $authorization = $request->headers->get('Authorization', '');

        if (\is_string($authorization) && str_starts_with($authorization, 'Bearer ')) {
            $bearer = substr($authorization, 7);
            if (null !== $this->jwt->parse($bearer)) {
                return null; // валидный JWT — аутентификацию выполняет AuthMiddleware
            }

            return $bearer; // API-ключ через Bearer (AR-3); пустой отсекается выше
        }

        $header = $request->headers->get('X-API-Key');

        return \is_string($header) ? $header : null;
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

<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Iam\Service\AuthMiddleware;
use App\Shared\Entity\IdempotencyKey;
use App\Shared\Idempotency\Exception\IdempotencyConflictException;
use App\Shared\Idempotency\IdempotencyService;
use App\Shared\Idempotency\IdempotencyState;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Идемпотентность мутаций (AR-4): заголовок Idempotency-Key на POST/PUT/PATCH/DELETE.
 *
 * kernel.request (после auth — нужен tenant):
 * - нет заголовка / не мутация / health, api-doc → пропуск;
 * - begin(): REPLAY → вернуть сохранённый ответ; CONFLICT → 409 idempotency_conflict;
 *   NEW → запомнить запись для сохранения ответа;
 * kernel.response:
 * - сохранить статус+body записи (ответ < 500), при 5xx — удалить (разрешён retry);
 * - TTL retention: begin() игнорирует истёкшие ключи (reuse), cleanup — Command.
 */
final class IdempotencyMiddleware implements EventSubscriberInterface
{
    private const string ATTR_RECORD = '_idempotency_record';
    private const array MUTATION_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private readonly IdempotencyService $service)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 80],
            KernelEvents::RESPONSE => ['onKernelResponse', -100],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $event->hasResponse()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        if (str_starts_with($path, '/health') || str_starts_with($path, '/api/doc')) {
            return;
        }
        if (!\in_array($request->getMethod(), self::MUTATION_METHODS, true)) {
            return;
        }

        $key = $request->headers->get('Idempotency-Key');
        if (!\is_string($key) || '' === $key) {
            return;
        }

        $tenantId = $this->tenantId($request);
        $hash = $this->service->requestHash($request->getMethod(), $path, (string) $request->getContent());

        $result = $this->service->begin($tenantId, $key, $request->getMethod(), $path, $hash);

        if (IdempotencyState::REPLAY === $result->state) {
            $record = $result->record;
            if (null === $record) {
                return;
            }
            $event->setResponse(new JsonResponse(
                $record->getResponseBody() ?? [],
                $record->getResponseStatus() ?? Response::HTTP_OK,
            ));

            return;
        }

        if (IdempotencyState::CONFLICT === $result->state) {
            throw new IdempotencyConflictException();
        }

        $record = $result->record;
        if ($record instanceof IdempotencyKey) {
            $request->attributes->set(self::ATTR_RECORD, $record);
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $record = $event->getRequest()->attributes->get(self::ATTR_RECORD);
        if (!$record instanceof IdempotencyKey) {
            return;
        }

        $response = $event->getResponse();
        $status = $response->getStatusCode();

        if ($status >= Response::HTTP_INTERNAL_SERVER_ERROR) {
            // серверная ошибка — не фиксируем ответ, клиент может повторить
            $this->service->discard($record);

            return;
        }

        $body = json_decode((string) $response->getContent(), true);

        $this->service->complete($record, $status, \is_array($body) ? $body : []);
    }

    private function tenantId(\Symfony\Component\HttpFoundation\Request $request): ?string
    {
        $user = $request->attributes->get(AuthMiddleware::ATTR_USER);
        if ($user instanceof \App\Iam\Entity\User) {
            return null !== $user->getCompanyId() ? (string) $user->getCompanyId() : null;
        }

        return null;
    }
}

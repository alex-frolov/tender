<?php

declare(strict_types=1);

namespace App\Iam\UseCase;

/**
 * Результат Iam-UseCase со статусом HTTP (auth-флоу с варьирующимися статусами
 * 200/202/401/429). Контроллер превращает его в JsonResponse:
 * `$this->json($result->payload, $result->status, $result->headers)`.
 *
 * Фиксированные статусы остаются в контроллере (пример: RegisterUseCase →
 * HTTP_CREATED); варьирующиеся (401 invalid credentials, 429 rate_limited)
 * несёт сам UseCase, чтобы оркестрация ответа не дублировалась в контроллере.
 */
final readonly class UseCaseResult
{
    /**
     * @param array<string, mixed>  $payload готовый к JSON ответ
     * @param array<string, string> $headers дополнительные HTTP-заголовки
     *                                       (Retry-After / X-RateLimit-*, rate_limited)
     */
    public function __construct(
        public int $status,
        public array $payload,
        public array $headers = [],
    ) {
    }
}

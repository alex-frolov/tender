<?php

declare(strict_types=1);

namespace App\Tender\Controller;

use App\Controller\AbstractBaseController;
use App\Security\TenderVoter;
use App\Tender\UseCase\PublishTenderUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Публикация тендера (FR-1.1.4): draft → published, расчёт таймлайна и
 * планирование авто-переходов по расписанию. Доступ: право tenders.publish
 * через TenderVoter (admin/manager; agent — 403). Принадлежность компании
 * (tenant-изоляция) и бизнес-правила — TenderService через PublishTenderUseCase.
 * Контракт: api/openapi.yaml (/tenders/{tenderId}/publish POST).
 */
final class TenderPublishController extends AbstractBaseController
{
    public const string URL = '/api/v1/tenders/{tenderId}/publish';

    #[Route(self::URL, name: 'tender_publish', methods: [Request::METHOD_POST])]
    #[IsGranted(TenderVoter::PUBLISH)]
    public function publish(Request $request, string $tenderId, PublishTenderUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->json($useCase->execute(
            user: $user,
            tenderId: $tenderId,
            ip: $request->getClientIp(),
        ));
    }
}

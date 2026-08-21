<?php

declare(strict_types=1);

namespace App\Bid\Controller;

use App\Bid\Form\AttachBidDocumentsType;
use App\Bid\Input\AttachBidDocumentsInput;
use App\Bid\UseCase\AttachBidDocumentsUseCase;
use App\Controller\AbstractBaseController;
use App\Security\BidVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Состав части 2 заявки — приложенные документы
 * (FR-1.2.1, POST /bids/{bidId}/documents).
 *
 * Отдельный вызов, а не поле при подаче: документ прикладывается к сущности
 * `bid` и до подачи заявки прикладывать его не к чему. Повторная подача для
 * этого не годится — она заменяет содержимое целиком, а автор своё содержимое
 * до вскрытия прочитать не может (оно зашифровано, FR-1.2.2).
 *
 * Доступ: право bids.submit (тот же, что и на подачу) — состав приложений
 * меняет тот, кто подаёт заявку. Владение и стадию проверяет BidService.
 * Контракт: api/openapi.yaml (/bids/{bidId}/documents POST).
 */
final class BidAttachDocumentsController extends AbstractBaseController
{
    public const string URL = '/api/v1/bids/{bidId}/documents';

    #[Route(self::URL, name: 'bid_attach_documents', methods: [Request::METHOD_POST])]
    #[IsGranted(BidVoter::SUBMIT)]
    public function attach(Request $request, string $bidId, AttachBidDocumentsUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $form = $this->formInput(AttachBidDocumentsType::class, $request);
        /** @var AttachBidDocumentsInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute(
            user: $user,
            bidId: $bidId,
            input: $input,
            ip: $request->getClientIp(),
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Bid\UseCase;

use App\Bid\BidPresenter;
use App\Bid\BidService;
use App\Bid\Input\AttachBidDocumentsInput;
use App\Iam\Entity\User;

/**
 * Привязка документов к части 2 заявки (FR-1.2.1,
 * POST /bids/{bidId}/documents).
 *
 * Документы существуют только после подачи заявки (они прикладываются
 * к сущности `bid`), поэтому состав части 2 задаётся отдельным вызовом,
 * а не при подаче. Владение заявкой, стадию приёма и принадлежность
 * документов проверяет BidService; ответ — карточка заявки.
 */
final readonly class AttachBidDocumentsUseCase implements BidUseCase
{
    public function __construct(
        private BidService $bids,
        private BidPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация заявки (openapi Bid)
     */
    public function execute(User $user, string $bidId, AttachBidDocumentsInput $input, ?string $ip = null): array
    {
        // metadata: до вскрытия состав заявки зашифрован и её автору тоже
        // не показывается — в ответе только метаданные (FR-1.2.2).
        return $this->presenter->metadata(
            $this->bids->attachDocuments($user, $bidId, $input->documentIds, $ip),
        );
    }
}

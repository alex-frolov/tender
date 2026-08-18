<?php

declare(strict_types=1);

namespace App\Complaint\UseCase;

use App\Complaint\Input\CreateComplaintInput;
use App\Complaint\Presenter\ComplaintPresenter;
use App\Complaint\Service\ComplaintService;
use App\Iam\Entity\User;
use App\Shared\Exception\ConflictException;

/**
 * Подача жалобы по тендеру (FR-1.2.10, POST /tenders/{tenderId}/complaints).
 *
 * Доступ — право tenders.qa через TenderQaVoter::FILE_COMPLAINT; принадлежность
 * лота тендеру и аудит — ComplaintService. Ответ — ComplaintPresenter::single
 * (openapi Complaint).
 */
final readonly class CreateComplaintUseCase implements ComplaintUseCase
{
    public function __construct(
        private ComplaintService $complaints,
        private ComplaintPresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed> презентация жалобы (openapi Complaint)
     *
     * @throws ConflictException если актор без компании
     */
    public function execute(User $user, string $tenderId, CreateComplaintInput $input, ?string $ip = null): array
    {
        $companyId = $user->getCompanyId();
        if (null === $companyId) {
            throw new ConflictException('Actor has no company');
        }

        return $this->presenter->single(
            $this->complaints->file($tenderId, $input, $companyId, (string) $user->getId(), $ip),
        );
    }
}

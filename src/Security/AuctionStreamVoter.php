<?php

declare(strict_types=1);

namespace App\Security;

use App\Auction\Entity\Auction;
use App\Auction\Entity\Enum\AuctionVisibilityLevelEnum;
use App\Bid\BidReadService;
use App\Bid\BidWinnerQuery;
use App\Iam\Entity\User;
use App\Tender\TenderVisibility;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Uid\Uuid;

/**
 * Voter доступа к live-данным аукциона (FR-1.3.4, R7, ADR-003).
 *
 * Круг доступа к аукциону (SUBSCRIBE — подписка на приватный topic
 * `auction:{id}`, VIEW — состояние GET /auctions/{id}/state и история ставок
 * GET /auctions/{id}/bids):
 * - заказчик (владелец тендера, тенант аукциона) — в любом статусе;
 * - наблюдатели (platform_admin — суперадмин платформы);
 * - остальным доступ зависит от статуса аукциона
 *   (AuctionStatusEnum::visibilityLevel, FR-1.5.14):
 *     * OWNER_ONLY (подготовка, пауза, выбор победителя, служебные статусы) —
 *       никому, кроме заказчика: это его внутренние решения;
 *     * TENDER_VIEWERS (идут торги) — всем, кому виден тендер, плюс допущенным
 *       участникам, даже если тендер перестал быть видимым (например, договор
 *       истёк посреди торгов);
 *     * OWNER_AND_WINNER (исполнение после APPROVE) — только компании-
 *       исполнителю: победителю лота (BidWinnerQuery).
 *
 * Ход торгов (цена, таймер, история ставок, live-события) публичен в пределах
 * видимости закупки; анонимность bidder_id до конца торгов обеспечивает
 * презентер, а не этот voter.
 *
 * Публикация (право publish) — только ядро (AuctionStreamPublisher), прав
 * у обычных пользователей нет.
 *
 * @extends Voter<string, Auction>
 */
final class AuctionStreamVoter extends Voter
{
    final public const string SUBSCRIBE = 'AuctionStreamSubscribe';
    final public const string VIEW = 'AuctionStreamView';

    public function __construct(
        private readonly BidReadService $bids,
        private readonly BidWinnerQuery $winners,
        private readonly TenderVisibility $tenderVisibility,
    ) {
    }

    protected function supports(string $attribute, $subject): bool
    {
        return \in_array($attribute, [self::SUBSCRIBE, self::VIEW], true)
            && $subject instanceof Auction;
    }

    protected function voteOnAttribute(string $attribute, $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$subject instanceof Auction) {
            return false;
        }

        // Наблюдатель — суперадмин платформы (R7).
        if ($user->getRole()->isPlatformAdmin()) {
            return true;
        }

        $companyId = $user->getCompanyId();
        if (null === $companyId) {
            return false;
        }

        // Заказчик — владелец тендера (tenant аукциона = tenantId тендера).
        if ($subject->getTenantId()->equals($companyId)) {
            return true;
        }

        return match ($subject->getStatus()->visibilityLevel()) {
            // Подготовка, пауза, выбор победителя, служебные статусы — только
            // заказчику: наружу этих стадий нет, даже допущенному участнику.
            AuctionVisibilityLevelEnum::OWNER_ONLY => false,
            AuctionVisibilityLevelEnum::TENDER_VIEWERS => $this->canSeeTrade($subject, $companyId),
            AuctionVisibilityLevelEnum::OWNER_AND_WINNER => $this->winners->isLotWinner(
                $subject->getTenderId(),
                $subject->getLotId(),
                $companyId,
            ),
        };
    }

    /**
     * Доступ к идущим торгам: виден тендер — виден и ход его аукциона
     * (FR-1.5.14). Допущенный участник проходит и без видимости тендера:
     * договор мог истечь уже посреди торгов, но выкидывать участника из
     * его же аукциона нельзя (FR-1.2.4).
     */
    private function canSeeTrade(Auction $auction, Uuid $companyId): bool
    {
        if ($this->tenderVisibility->isVisible($auction->getTenderId(), $companyId)) {
            return true;
        }

        return $this->bids->isAdmitted($auction->getTenderId(), $auction->getLotId(), $companyId);
    }
}

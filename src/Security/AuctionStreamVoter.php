<?php

declare(strict_types=1);

namespace App\Security;

use App\Auction\Entity\Auction;
use App\Bid\BidReadService;
use App\Iam\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter доступа к live-данным аукциона (FR-1.3.4, R7, ADR-003).
 *
 * Приватный topic `auction:{id}`: подписка (право sub) только для
 * - допущенных участников (bids.status = admitted, FR-1.2.4);
 * - заказчика (владелец тендера, customerId);
 * - наблюдателей (platform_admin — суперадмин платформы).
 *
 * Тот же круг (R7) имеет право VIEW: состояние аукциона
 * (GET /auctions/{id}/state) и историю ставок (GET /auctions/{id}/bids) —
 * публикация live-данных вне этого круга недоступна.
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

    public function __construct(private readonly BidReadService $bids)
    {
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

        // Допущенный участник: admitted-заявка на лот аукциона (FR-1.2.4).
        return $this->bids->isAdmitted(
            $subject->getTenderId(),
            $subject->getLotId(),
            $companyId,
        );
    }
}

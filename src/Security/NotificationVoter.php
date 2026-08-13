<?php

declare(strict_types=1);

namespace App\Security;

use App\Iam\Entity\User;
use App\Iam\Service\PermissionCheckerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter прав на подписки уведомлений (FR-1.6.3).
 *
 * Подписки — self-service «мои подписки» (AM-11): право notifications.subscribe
 * (группа common, по умолчанию включено всем ролям компании). Subject не
 * используется: подписки принадлежат самому актору, tenant-изоляцию (404 для
 * чужих) выполняет NotificationSubscriptionService.
 *
 * @extends Voter<string, null>
 */
final class NotificationVoter extends Voter
{
    final public const string SUBSCRIBE = 'NotificationSubscribe';

    private const string CODE = 'notifications.subscribe';

    public function __construct(private readonly PermissionCheckerInterface $permissions)
    {
    }

    protected function supports(string $attribute, $subject): bool
    {
        return self::SUBSCRIBE === $attribute && null === $subject;
    }

    protected function voteOnAttribute(string $attribute, $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return $this->permissions->can($user, self::CODE);
    }
}

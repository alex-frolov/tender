<?php

declare(strict_types=1);

namespace App\Security;

use App\Iam\Entity\User;
use App\Iam\Service\PermissionCheckerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter прав на webhook-подписки (WH-7, FR-1.5.10/1.5.15).
 *
 * Все операции с подписками (list/create/update/delete/rotate/deliveries) —
 * право webhooks.manage (группа platform): admin/platform_admin — всегда,
 * manager/agent — по настройке (по умолчанию отключено). Subject не
 * используется: подписки принадлежат компании актора, tenant-изоляцию
 * (404 для чужих) выполняет WebhookService.
 *
 * @extends Voter<string, null>
 */
final class WebhookVoter extends Voter
{
    final public const string MANAGE = 'WebhookManage';

    private const string CODE = 'webhooks.manage';

    public function __construct(private readonly PermissionCheckerInterface $permissions)
    {
    }

    protected function supports(string $attribute, $subject): bool
    {
        return self::MANAGE === $attribute && null === $subject;
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

<?php

declare(strict_types=1);

namespace App\Security;

use App\Iam\Entity\User;
use App\Iam\Service\PermissionCheckerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter прав на сохранённые поиски и избранное (F-A5/A6, AM-12).
 *
 * Self-service «мои данные»: право search.save (сохранённые поиски) и
 * favorites.manage (избранное/заметки) — группа common, по умолчанию включено
 * всем ролям компании (как notifications.subscribe). Subject не используется:
 * данные принадлежат самому актору, tenant-изоляцию (404 для чужих) выполняют
 * SavedSearchService/FavoriteService.
 *
 * @extends Voter<string, null>
 */
final class SavedSearchVoter extends Voter
{
    final public const string SEARCH = 'SavedSearchManage';
    final public const string FAVORITES = 'FavoriteManage';

    /** @var array<string, string> атрибут → permission code */
    private const CODES = [
        self::SEARCH => 'search.save',
        self::FAVORITES => 'favorites.manage',
    ];

    public function __construct(private readonly PermissionCheckerInterface $permissions)
    {
    }

    protected function supports(string $attribute, $subject): bool
    {
        return null === $subject && isset(self::CODES[$attribute]);
    }

    protected function voteOnAttribute(string $attribute, $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return $this->permissions->can($user, self::CODES[$attribute]);
    }
}

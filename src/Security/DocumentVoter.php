<?php

declare(strict_types=1);

namespace App\Security;

use App\Iam\Entity\User;
use App\Iam\Service\PermissionCheckerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter прав на документы (AM-8, FR-1.2.6/1.2.7).
 *
 * Проверяет конкретное действие (permission code) через PermissionCheckService:
 *   UPLOAD (tenders.manage_docs) — admin ✅ manager ✅ agent ✅
 *   VIEW   (tenders.board.view)  — admin ✅ manager ✅ agent ✅
 * Принадлежность документа (tenant-изоляция) и правила видимости (FR-1.2.6)
 * обеспечивает DocumentService (404/403).
 *
 * @extends Voter<string, mixed>
 */
final class DocumentVoter extends Voter
{
    final public const string UPLOAD = 'DocumentUpload';
    final public const string VIEW = 'DocumentView';

    /** @var array<string, string> атрибут → permission code */
    private const array CODES = [
        self::UPLOAD => 'tenders.manage_docs',
        self::VIEW => 'tenders.board.view',
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

<?php

declare(strict_types=1);

namespace App\Security;

use App\Iam\Entity\User;
use App\Iam\Service\PermissionCheckerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter прав на вопросы/ответы по тендеру (FR-1.2.9).
 *
 * Вопросы, ответы и жалобы — право tenders.qa (группа customer, каталог
 * domain/permissions.md): admin — всегда, manager/agent — по настройке.
 * Subject не используется: принадлежность лота/тендера проверяет сервис
 * (TenderQuestionService через TenderReadService). Там же проверяется сторона
 * для ANSWER: отвечает только заказчик процедуры, тогда как спрашивают
 * этим же правом и участники.
 *
 * @extends Voter<string, null>
 */
final class TenderQaVoter extends Voter
{
    final public const string ASK = 'TenderQaAsk';
    final public const string ANSWER = 'TenderQaAnswer';
    final public const string LIST = 'TenderQaList';
    final public const string FILE_COMPLAINT = 'TenderQaFileComplaint';

    private const string CODE = 'tenders.qa';

    /** @var array<string, string> атрибут → permission code */
    private const array CODES = [
        self::ASK => self::CODE,
        self::ANSWER => self::CODE,
        self::LIST => self::CODE,
        self::FILE_COMPLAINT => self::CODE,
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

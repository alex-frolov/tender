<?php

declare(strict_types=1);

namespace App\Bid;

use App\Bid\Entity\Bid;
use App\Bid\Entity\Enum\BidDecisionEnum;
use App\Bid\Entity\Enum\BidStatusEnum;
use App\Bid\Exception\BidNotFoundException;
use App\Bid\Repository\BidRepository;
use App\Bid\Service\BidTransaction;
use App\Contract\ContractAccessChecker;
use App\Document\DocumentService;
use App\Iam\CompanyAccessGuard;
use App\Iam\Entity\Enum\UserStatusEnum;
use App\Iam\Entity\User;
use App\Shared\Exception\ConflictException;
use App\Shared\Exception\StateTransitionException;
use App\Shared\Exception\ValidationException;
use App\Tender\Entity\Enum\AccessTypeEnum;
use App\Tender\Entity\Enum\PriceBasisEnum;
use App\Tender\Entity\Enum\TenderStatusEnum;
use App\Tender\Entity\Lot;
use App\Tender\Entity\Tender;
use App\Tender\TenderReadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Заявки (FR-1.2, AM-4): подача, замена, отзыв.
 *
 * Сервис — оркестратор: валидация (приём заявок, компания актора, доступ для
 * contract_holders), шифрование содержимого (BidPayloadCipher, FR-1.2.2),
 * уведомление об отклонении. Транзакционный «хвост» (persist/flush + append-only
 * аудит FR-1.8 + outbox bid.*) вынесен во внутренний support-класс
 * `Bid\Service\BidTransaction`.
 *
 * - Содержимое заявки (part1 — согласие/характеристики, part2_ref — документы,
 *   цена) шифруется BidPayloadCipher и хранится ТОЛЬКО зашифрованным
 *   (encrypted_payload, FR-1.2.2); метаданные (supplier, lot, status, время)
 *   — открытые колонки и доступны до вскрытия.
 * - Инвариант «одна заявка на лот» (data-model unique (tender_id, lot_id,
 *   supplier_id)): повторная подача до окончания приёма = ЗАМЕНА существующей
 *   заявки (FR-1.2.5) — обновляется содержимое, статус снова submitted,
 *   submitted_at переписывается; новая строка НЕ создаётся.
 * - Подача/замена возможны только пока тендер принимает заявки (accepting_bids);
 *   отзыв (FR-1.2.5) — тоже только до окончания приёма.
 * - Тендер публичный: принадлежность тендера компании актора не проверяется
 *   (участвует исполнитель из любой компании); tenant заявки = тенант тендера.
 * - Отзыв доступен только компании-владельцу заявки (supplierId).
 */
final readonly class BidService
{
    public function __construct(
        private EntityManagerInterface $em,
        private BidRepository $bids,
        private BidPayloadCipher $cipher,
        private CompanyAccessGuard $companyGuard,
        private TenderReadService $tenders,
        private ContractAccessChecker $contractAccess,
        private DocumentService $documents,
        private BidTransaction $transaction,
        private MailerInterface $mailer,
        private Environment $twig,
        private TranslatorInterface $translator,
        private string $from,
    ) {
    }

    /**
     * Подача заявки участником (FR-1.2.1). Содержимое шифруется (FR-1.2.2),
     * статус → submitted.
     *
     * Если у компании уже есть заявка на этот лот и тендер всё ещё принимает
     * заявки — это ЗАМЕНА (FR-1.2.5): содержимое обновляется, статус снова
     * submitted, submitted_at переписывается (одна заявка на лот, новая строка
     * не создаётся). Подача/замена возможны только в accepting_bids.
     *
     * @param array<string, mixed> $part1              часть 1: согласие, характеристики
     * @param list<string>         $part2Ref           часть 2: id документов заявки
     * @param int|null             $priceMinor         цена предложения (для конкурсных процедур)
     * @param Uuid|null            $declaredSupplierId supplier_id из тела (openapi BidCreate);
     *                                                 если указан, должен совпадать с компанией актора
     *
     * @throws ConflictException если актор без компании, компания не активна,
     *                           тендер не принимает заявки, лот не из этого тендера,
     *                           supplier_id не совпадает с компанией актора
     */
    public function submit(
        User $actor,
        Tender $tender,
        ?string $lotId,
        array $part1,
        array $part2Ref,
        ?int $priceMinor,
        ?PriceBasisEnum $priceBasis,
        ?float $vatRate,
        ?string $ip = null,
        ?Uuid $declaredSupplierId = null,
    ): Bid {
        $supplierId = $this->requireCompany($actor);
        if (null !== $declaredSupplierId && !$declaredSupplierId->equals($supplierId)) {
            throw new ConflictException('supplier_id does not match the authenticated company');
        }
        $this->companyGuard->assertActive($supplierId);

        // Закрытые тендеры (contract_holders, FR-1.5.14): участвовать может только
        // исполнитель с действующим multi_use-договором с заказчиком (FR-1.5.14,
        // ). Проверка доступа при подаче заявки и на входе в аукцион.
        if (AccessTypeEnum::CONTRACT_HOLDERS === $tender->getAccessType()) {
            $this->contractAccess->assertCanParticipate($tender->getCustomerId(), $supplierId);
        }

        $this->assertAcceptingBids($tender);
        $this->assertPrice($priceMinor);

        $lot = $this->tenders->resolveLot($tender->getId(), $lotId);

        // Заявка «на тендер целиком» (lot_id = null) у тендера с лотами —
        // тупик: аукцион создаётся по лоту, а допуск к торгам сверяется парой
        // (тендер, лот) — BidRepository::isAdmitted. Такую заявку можно подать
        // и даже допустить, но торговаться по ней нельзя: аукцион ответит
        // «Only admitted participants». Отклоняем на входе, а не оставляем
        // участника выяснять это на старте торгов. lot_id остаётся
        // необязательным для тендеров без лотов (единый предмет закупки).
        if (null === $lot && !$tender->getLots()->isEmpty()) {
            throw new ValidationException('lot_id is required: this tender has lots');
        }

        $existing = $this->bids->findDuplicate($tender->getId(), $lot?->getId(), $supplierId);
        if (null !== $existing) {
            return $this->replace($actor, $existing, $lot, $part1, $part2Ref, $priceMinor, $priceBasis, $vatRate, $ip);
        }

        $bid = new Bid($tender->getId(), $lot?->getId(), $supplierId, $tender->getTenantId());
        $bid->setEncryptedPayload($this->cipher->encrypt([
            'part1' => $part1,
            'part2_ref' => $part2Ref,
            'price_minor' => $priceMinor,
            'price_basis' => $priceBasis?->value,
            'vat_rate' => $vatRate,
        ]));
        $bid->submit();

        $this->transaction->commitSubmitted($bid, $actor, $tender, $lot, $supplierId, $ip);

        return $bid;
    }

    /**
     * Привязка документов к части 2 заявки (FR-1.2.1/1.2.6,
     * POST /bids/{bidId}/documents).
     *
     * Документы прикладываются к сущности `bid`, а значит существуют только
     * после подачи заявки — заранее их приложить не к чему. Отдельный вызов
     * нужен именно поэтому: повторная подача заявки заменяет содержимое
     * целиком (part1, цена), а автор до вскрытия своё содержимое прочитать
     * не может — оно зашифровано (FR-1.2.2). Пришлось бы заново вводить всё,
     * чтобы добавить один файл.
     *
     * Список заменяет прежний целиком: часть 2 — это состав приложений, а не
     * журнал добавлений. Заявку правит только её подавший и только пока идёт
     * приём заявок.
     *
     * @param list<string> $documentIds id документов (сущность bid)
     *
     * @throws BidNotFoundException     если заявки нет или она чужая
     * @throws StateTransitionException если заявка не в статусе submitted
     * @throws ValidationException      если документ не принадлежит этой заявке
     */
    public function attachDocuments(User $actor, string $bidId, array $documentIds, ?string $ip = null): Bid
    {
        $companyId = $this->requireCompany($actor);
        $bid = $this->bids->findById($bidId);
        if (null === $bid || !$bid->getSupplierId()->equals($companyId)) {
            throw new BidNotFoundException('Bid not found');
        }

        $tender = $this->tenders->resolveTender((string) $bid->getTenderId());
        $this->assertAcceptingBids($tender);

        if (BidStatusEnum::SUBMITTED !== $bid->getStatus()) {
            throw new StateTransitionException('Only submitted bids can be updated');
        }

        // Документ обязан быть приложен к ЭТОЙ заявке: иначе часть 2 ссылалась бы
        // на чужой файл, который заказчик после вскрытия открыть не сможет.
        foreach ($documentIds as $documentId) {
            if (!$this->documents->belongsToEntity($documentId, 'bid', $bid->getId())) {
                throw new ValidationException(\sprintf('document %s does not belong to this bid', $documentId));
            }
        }

        $payload = $this->cipher->decrypt($bid->getEncryptedPayload());
        $payload['part2_ref'] = array_values($documentIds);
        $bid->setEncryptedPayload($this->cipher->encrypt($payload));

        $this->transaction->commitDocumentsAttached($bid, $actor, $documentIds, $ip);

        return $bid;
    }

    /**
     * Отзыв заявки (FR-1.2.5, AM-4): submitted → withdrawn с обязательной
     * причиной. Только владелец заявки (supplierId = компания актора) и только
     * пока тендер принимает заявки (до окончания приёма). Причина сохраняется
     * в decision_reason и аудите.
     *
     * @throws BidNotFoundException     если заявка не найдена или не принадлежит актору
     * @throws ConflictException        если тендер больше не принимает заявки
     * @throws StateTransitionException если заявка уже не в статусе submitted
     * @throws ValidationException      если причина не указана
     */
    public function withdraw(User $actor, string $bidId, string $reason, ?string $ip = null): Bid
    {
        $companyId = $this->requireCompany($actor);
        $bid = $this->bids->findById($bidId);
        if (null === $bid || !$bid->getSupplierId()->equals($companyId)) {
            throw new BidNotFoundException('Bid not found');
        }

        $tender = $this->tenders->resolveTender((string) $bid->getTenderId());
        $this->assertAcceptingBids($tender);

        if (BidStatusEnum::SUBMITTED !== $bid->getStatus()) {
            throw new StateTransitionException('Only submitted bids can be withdrawn');
        }
        if ('' === trim($reason)) {
            throw new ValidationException('reason is required');
        }

        $before = $bid->getStatus();
        $bid->withdraw($reason);

        $this->transaction->commitWithdrawn($bid, $actor, $before, $reason, $ip);

        return $bid;
    }

    /**
     * Допуск/отклонение заявки (FR-1.2.4, UC-05, AM-4). Рассмотрение выполняет
     * заказчик (тенант тендера): submitted → admitted | rejected с ОБЯЗАТЕЛЬНОЙ
     * причиной. Причина сохраняется в decision_reason и аудите. Отклонение —
     * с уведомлением участника (email в компанию поставщика).
     *
     * Право: bids.qualify через BidVoter (admin/manager; agent — 403).
     *
     * @throws BidNotFoundException     если заявка не найдена или не из тендера
     *                                  компании актора (tenant-изоляция, 404)
     * @throws StateTransitionException если заявка уже не в статусе submitted
     * @throws ValidationException      если decision не admit/reject или причина
     *                                  не указана
     */
    public function qualify(User $actor, string $bidId, string $decision, string $reason, ?string $ip = null): Bid
    {
        $companyId = $this->requireCompany($actor);
        $bid = $this->bids->findById($bidId);
        if (null === $bid || !$bid->getTenantId()->equals($companyId)) {
            throw new BidNotFoundException('Bid not found');
        }

        $decisionEnum = BidDecisionEnum::tryFrom($decision);
        if (null === $decisionEnum) {
            throw new ValidationException('decision must be admit or reject');
        }
        if ('' === trim($reason)) {
            throw new ValidationException('reason is required');
        }
        if (BidStatusEnum::SUBMITTED !== $bid->getStatus()) {
            throw new StateTransitionException('Only submitted bids can be qualified');
        }

        $target = BidDecisionEnum::ADMIT === $decisionEnum
            ? BidStatusEnum::ADMITTED
            : BidStatusEnum::REJECTED;

        $before = $bid->getStatus();
        $bid->setStatus($target);
        $bid->setDecisionReason($reason);

        if (BidDecisionEnum::REJECT === $decisionEnum) {
            $this->notifyRejection($bid, $reason);
        }

        $this->transaction->commitQualified($bid, $actor, $before, $decisionEnum, $reason, $ip);

        return $bid;
    }

    /**
     * Уведомление участника об отклонении заявки (FR-1.2.4, FR-1.6):
     * письмо всем активным пользователям компании-поставщика. Отправка
     * асинхронная через messenger-канал `emails` (routing SendEmailMessage).
     */
    private function notifyRejection(Bid $bid, string $reason): void
    {
        $tender = $this->tenders->resolveTender((string) $bid->getTenderId());

        /** @var list<User> $users */
        $users = $this->em->getRepository(User::class)
            ->createQueryBuilder('u')
            ->where('u.companyId = :companyId')
            ->andWhere('u.verificationStatus <> :deleted')
            ->setParameter('companyId', $bid->getSupplierId())
            ->setParameter('deleted', UserStatusEnum::DELETED->value)
            ->getQuery()
            ->getResult();

        foreach ($users as $user) {
            $locale = $user->getLocale()->value;
            $email = (new Email())
                ->from($this->from)
                ->to($user->getEmail())
                ->subject($this->translator->trans('subject', [], 'bid_rejected.subject', $locale))
                ->text($this->twig->render('email/bid_rejected.txt.twig', [
                    'tender_number' => $tender->getNumber(),
                    'tender_title' => $tender->getTitle(),
                    'reason' => $reason,
                    'locale' => $locale,
                ]));

            $this->mailer->send($email);
        }
    }

    /**
     * Карточка заявки по id. Права доступа (владелец/заказчик) — здесь:
     * только компания-владелец (supplierId) может читать свою заявку;
     * содержимое остаётся зашифрованным до вскрытия (FR-1.2.2).
     *
     * @throws BidNotFoundException если заявка не найдена или не принадлежит актору
     */
    public function getForOwner(User $actor, string $bidId): Bid
    {
        $companyId = $this->requireCompany($actor);
        $bid = $this->bids->findById($bidId);
        if (null === $bid || !$bid->getSupplierId()->equals($companyId)) {
            throw new BidNotFoundException('Bid not found');
        }

        return $bid;
    }

    /**
     * Заявки тендера для компании актора (FR-1.2.2/1.2.3):
     * - заказчик (тенант тендера) видит все заявки по своему тендеру;
     * - участник ВСЕГДА видит свои заявки — в любом статусе: это его
     *   собственная заявка, и её карточка (решение по допуску, приложенная
     *   часть 2) нужна ему и после рассмотрения;
     * - участник ПОСЛЕ вскрытия видит вдобавок чужие поданные (submitted)
     *   заявки — содержимое отдаётся «в части» (part1, FR-1.2.3); чужие
     *   отозванные и отклонённые не видны.
     *
     * Раньше после вскрытия выборка сводилась к submitted целиком, и своя
     * заявка исчезала из списка сразу после допуска (admitted): участник
     * терял и решение заказчика, и раздел документов части 2.
     *
     * @return list<Bid>
     */
    public function listForCompany(User $actor, Tender $tender): array
    {
        $companyId = $this->requireCompany($actor);
        $all = $this->bids->listForTender($tender->getId());

        // заказчик (владелец тендера) видит все заявки по своему тендеру
        if ($tender->getTenantId()->equals($companyId)) {
            return $all;
        }

        // участник: свои заявки всегда, чужие поданные — только после вскрытия
        $opened = null !== $tender->getBidsOpenedAt();

        return array_values(array_filter(
            $all,
            static fn (Bid $b): bool => $b->getSupplierId()->equals($companyId)
                || ($opened && BidStatusEnum::SUBMITTED === $b->getStatus()),
        ));
    }

    /**
     * @throws ConflictException если актор без компании
     */
    private function requireCompany(User $actor): Uuid
    {
        $companyId = $actor->getCompanyId();
        if (null === $companyId) {
            throw new ConflictException('Actor has no company');
        }

        return $companyId;
    }

    /**
     * Заявки принимаются только пока тендер в статусе accepting_bids
     * (FR-1.2.1, таймлайн: после bids_end приём закрыт). Это же условие
     * ограничивает замену и отзыв (FR-1.2.5 — «до окончания приёма»).
     *
     * Deadline bids_end из таймлайна (рассчитан TimelineRules при публикации)
     * — вторая граница: даже если статус ещё accepting_bids (вскрытие на
     * bids_end обрабатывается worker'ом с задержкой), подача после срока
     * отклоняется. Тендеры без таймлайна (тесты/черновики) лимита не имеют.
     *
     * Тендер без заявок на участие (bids_required=false, FR-1.2.1) отклоняется
     * отдельным сообщением: он никогда не бывает в accepting_bids (published →
     * bidding напрямую), и общий текст «приём заявок закрыт» вводил бы
     * в заблуждение — приёма заявок в такой процедуре нет вовсе.
     *
     * @throws ConflictException если тендер не принимает заявки
     */
    private function assertAcceptingBids(Tender $tender): void
    {
        if (!$tender->isBidsRequired()) {
            throw new ConflictException('This tender does not require participation bids');
        }

        if (TenderStatusEnum::ACCEPTING_BIDS !== $tender->getStatus()) {
            throw new ConflictException('Bids are accepted only while the tender is accepting bids');
        }

        $bidsEnd = $tender->getTimeline()['bids_end'] ?? null;
        if (null !== $bidsEnd) {
            $deadline = new \DateTimeImmutable($bidsEnd, new \DateTimeZone('UTC'));
            if (new \DateTimeImmutable('now', new \DateTimeZone('UTC')) >= $deadline) {
                throw new ConflictException('Bid acceptance deadline (bids_end) has passed');
            }
        }
    }

    /**
     * Замена заявки (FR-1.2.5): у компании уже есть заявка на лот и тендер
     * всё ещё принимает заявки. Обновляется зашифрованное содержимое, статус
     * снова submitted, submitted_at переписывается. Одна заявка на лот
     * (data-model unique) сохраняется — новая строка не создаётся.
     *
     * @param array<string, mixed> $part1
     * @param list<string>         $part2Ref
     */
    private function replace(
        User $actor,
        Bid $bid,
        ?Lot $lot,
        array $part1,
        array $part2Ref,
        ?int $priceMinor,
        ?PriceBasisEnum $priceBasis,
        ?float $vatRate,
        ?string $ip,
    ): Bid {
        $before = $bid->getStatus();
        $bid->setEncryptedPayload($this->cipher->encrypt([
            'part1' => $part1,
            'part2_ref' => $part2Ref,
            'price_minor' => $priceMinor,
            'price_basis' => $priceBasis?->value,
            'vat_rate' => $vatRate,
        ]));
        $bid->submit();

        $this->transaction->commitReplaced($bid, $actor, $lot, $before, $ip);

        return $bid;
    }

    /**
     * Цена предложения — неотрицательная (если указана).
     *
     * @throws ConflictException если цена отрицательная
     */
    private function assertPrice(?int $priceMinor): void
    {
        if (null !== $priceMinor && $priceMinor < 0) {
            throw new ConflictException('price_minor must be non-negative');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Question\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Вопрос/ответ по тендеру (tender_questions, FR-1.2.9, openapi Question).
 *
 * Участники задают вопросы по тендеру/лоту (lot_id опционален); заказчик
 * отвечает — публикация ответа выставляет answer + published_at. Тендер
 * резолвится публичным TenderReadService (границы модулей).
 */
#[ORM\Entity]
#[ORM\Table(name: 'tender_questions')]
#[ORM\Index(name: 'idx_tender_questions_tender', columns: ['tender_id'])]
class TenderQuestion
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $tenderId;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $lotId = null;

    #[ORM\Column(type: 'text')]
    private string $text;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $answer = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Uuid $tenderId, ?Uuid $lotId, string $text)
    {
        $this->id = Uuid::v4();
        $this->tenderId = $tenderId;
        $this->lotId = $lotId;
        $this->text = $text;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * Публикация ответа заказчика (FR-1.2.9): ответ и момент публикации
     * проставляются вместе — вопрос считается разъяснённым ровно тогда,
     * когда ответ стал виден участникам.
     */
    public function publishAnswer(string $answer, \DateTimeImmutable $now): void
    {
        $this->answer = $answer;
        $this->publishedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenderId(): Uuid
    {
        return $this->tenderId;
    }

    public function getLotId(): ?Uuid
    {
        return $this->lotId;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getAnswer(): ?string
    {
        return $this->answer;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

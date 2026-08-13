<?php

declare(strict_types=1);

namespace App\RuStateProcurement\Event;

use App\RuStateProcurement\Protocol\RuProtocolGenerator;
use App\Shared\Events\EventMessage;
use Psr\Log\LoggerInterface;

/**
 * Реакция плагина ru-state-procurement на доменные события ядра
 * (вызывается из App\Infrastructure\Messenger\EventMessageHandler).
 *
 * Протоколы генерируются «ядром из событий, оформляются плагином» (§6
 * domain/plugins/ru-state-procurement.md):
 * - tender.opened → протокол вскрытия заявок;
 * - auction.winner_chosen → итоговый протокол.
 *
 * Плагин можно отключить через feature-flag PROCUREMENT_PLUGIN_ENABLED
 * (контракт ядра при этом не задействуется — генерация пропускается).
 */
final readonly class RuProtocolListener
{
    public function __construct(
        private RuProtocolGenerator $protocols,
        private bool $enabled,
        private LoggerInterface $logger,
    ) {
    }

    public function apply(EventMessage $message): void
    {
        if (!$this->enabled) {
            return;
        }

        $documentId = match ($message->eventType) {
            'tender.opened' => $this->protocols->generateOpeningProtocol($message),
            'auction.winner_chosen' => $this->protocols->generateFinalProtocol($message),
            default => null,
        };

        if (null !== $documentId) {
            $this->logger->info('ru-state-procurement: protocol generated', [
                'event_id' => $message->eventId,
                'event_type' => $message->eventType,
                'document_id' => $documentId,
            ]);
        }
    }
}

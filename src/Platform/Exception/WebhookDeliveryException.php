<?php

declare(strict_types=1);

namespace App\Platform\Exception;

/**
 * Временный сбой доставки webhook (WH-5): подписчик недоступен/ответил ошибкой.
 *
 * Бросается из WebhookDeliveryMessageHandler на непоследней попытке —
 * messenger-ретраи (transport `webhooks`, retry_strategy: delay 1s, multiplier 5)
 * переотправят задачу с экспоненциальной задержкой (1/5/25/125 сек).
 * После исчерпания лимита попыток доставка помечается dead (dead-letter) и
 * публикуется platform.webhook.failed — исключение при этом не бросается.
 */
final class WebhookDeliveryException extends \RuntimeException
{
}

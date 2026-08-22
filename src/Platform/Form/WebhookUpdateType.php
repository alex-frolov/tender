<?php

declare(strict_types=1);

namespace App\Platform\Form;

use App\Platform\Entity\Enum\WebhookStatusEnum;
use App\Platform\Entity\Webhook;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Event\PreSubmitEvent;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Url;

/**
 * Форма изменения webhook-подписки (WH-7, PATCH /webhooks/{id}).
 *
 * Форма привязана к сущности Webhook (entity-bound update form, AGENTS.md):
 * контроллер резолвит подписку через WebhookRepository::findOwnedOrFail
 * (tenant-изоляция, 404 для чужих) и передаёт её как data. Поля, отсутствующие
 * в теле, сохраняют текущие значения (clearMissing: false). Секрет здесь не
 * меняется — для него отдельный эндпоинт /rotate-secret (WH-7).
 *
 * Свойства сущности NOT NULL, поэтому url/status имеют empty_data-замыкания
 * с текущим значением (пустая строка/null = «оставить»). Пустой список events
 * отклоняется валидацией (Count), а не молча очищает подписку.
 *
 * @extends AbstractType<Webhook>
 */
final class WebhookUpdateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('url', UrlType::class, [
                'required' => false,
                'default_protocol' => 'https',
                'empty_data' => static fn (FormInterface $form): string => self::current($form)?->getUrl() ?? '',
                'constraints' => [
                    new Length(max: 2048),
                    new Url(protocols: ['http', 'https'], message: 'url must be a valid URL'),
                ],
            ])
            ->add('events', CollectionType::class, [
                'required' => false,
                'allow_add' => true,
                'entry_type' => TextType::class,
                'constraints' => [
                    new All([
                        new NotBlank(),
                        new Regex(
                            '/^[a-z]+\.[a-z_]+$/',
                            'event must match "prefix.action" (e.g. tender.published)',
                        ),
                    ]),
                ],
            ])
            ->add('status', EnumType::class, [
                'class' => WebhookStatusEnum::class,
                'required' => false,
                'empty_data' => static fn (FormInterface $form): WebhookStatusEnum => self::current($form)?->getStatus() ?? WebhookStatusEnum::ACTIVE,
            ])
        ;

        // Очистить список событий у подписки нельзя (подписка без событий
        // бессмысленна). При clearMissing=false явный events: [] до полей
        // коллекции не доходит — существующие элементы просто остаются, — так
        // что отклоняем его на входе, а не констрейнтом на уже собранных данных.
        $builder->get('events')->addEventListener(
            FormEvents::PRE_SUBMIT,
            static function (PreSubmitEvent $event): void {
                if ([] === $event->getData()) {
                    $event->getForm()->addError(new FormError('events must not be empty'));
                }
            },
        );

        // Дубли в списке событий бессмысленны: одно событие — одна доставка.
        $builder->get('events')->addModelTransformer(new CallbackTransformer(
            transform: static fn (mixed $events): mixed => $events,
            reverseTransform: static fn (mixed $events): mixed => self::dedupe($events),
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => Webhook::class,
        ]);
    }

    /**
     * Список событий без повторов, порядок первых вхождений сохраняется.
     * Значения не-строк (валидные типы отсеет All/NotBlank ниже по конвейеру)
     * пропускаются как есть.
     */
    private static function dedupe(mixed $events): mixed
    {
        if (!\is_array($events)) {
            return $events;
        }

        $seen = [];
        $unique = [];
        foreach ($events as $event) {
            if (\is_string($event)) {
                if (isset($seen[$event])) {
                    continue;
                }
                $seen[$event] = true;
            }
            $unique[] = $event;
        }

        return $unique;
    }

    /**
     * Резолвленная подписка (data родительской формы) — источник текущих
     * значений для empty_data.
     *
     * @param FormInterface<object> $form
     */
    private static function current(FormInterface $form): ?Webhook
    {
        $webhook = $form->getParent()?->getData();

        return $webhook instanceof Webhook ? $webhook : null;
    }
}

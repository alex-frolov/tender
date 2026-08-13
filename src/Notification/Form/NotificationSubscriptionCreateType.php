<?php

declare(strict_types=1);

namespace App\Notification\Form;

use App\Notification\Entity\Enum\NotificationChannelEnum;
use App\Notification\Input\CreateNotificationSubscriptionInput;
use App\Shared\Form\WebhookFiltersType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Форма создания подписки на уведомления (FR-1.6, openapi POST
 * /notifications/subscriptions). Обязательные: channel, events. digest — флаг
 * дайджеста (по умолчанию false). Валидация формата событий (domain/events.md):
 * `префикс.действие` (tender.published и т.п.).
 *
 * @extends AbstractType<CreateNotificationSubscriptionInput>
 */
final class NotificationSubscriptionCreateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('channel', ChoiceType::class, [
                'property_path' => 'channel',
                'empty_data' => '',
                'choices' => NotificationChannelEnum::getValues(),
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            ->add('events', CollectionType::class, [
                'property_path' => 'events',
                'allow_add' => true,
                'entry_type' => TextType::class,
                'constraints' => [
                    new NotBlank(),
                    new Count(min: 1),
                    new All([
                        new NotBlank(),
                        new Regex(
                            '/^[a-z]+\.[a-z_]+$/',
                            'event must match "prefix.action" (e.g. tender.published)',
                        ),
                    ]),
                ],
            ])
            ->add('filters', WebhookFiltersType::class, [
                'property_path' => 'filters',
                'required' => false,
            ])
            ->add('digest', CheckboxType::class, [
                'property_path' => 'digest',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => CreateNotificationSubscriptionInput::class,
        ]);
    }
}

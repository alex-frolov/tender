<?php

declare(strict_types=1);

namespace App\Platform\Form;

use App\Platform\Entity\Enum\WebhookStatusEnum;
use App\Platform\Input\CreateWebhookInput;
use App\Shared\Form\WebhookFiltersType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Форма создания webhook-подписки (WH-7, POST /webhooks).
 * Имена полей — snake_case (openapi WebhookCreate). Обязательные: url, events.
 * secret необязателен (если пуст — генерируется сервисом, WH-3). Валидация
 * формата событий (domain/events.md): `префикс.действие` (tender.published и т.п.).
 *
 * @extends AbstractType<CreateWebhookInput>
 */
final class WebhookCreateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('url', UrlType::class, [
                'property_path' => 'url',
                'empty_data' => '',
                'default_protocol' => 'https',
                'constraints' => [
                    new NotBlank(),
                    new Length(max: 2048),
                ],
            ])
            ->add('secret', TextType::class, [
                'property_path' => 'secret',
                'required' => false,
                'constraints' => [
                    new Length(min: 16, max: 128),
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
            ->add('status', ChoiceType::class, [
                'required' => false,
                'placeholder' => WebhookStatusEnum::ACTIVE->value,
                'choices' => WebhookStatusEnum::getValues(),
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => CreateWebhookInput::class,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Platform\Form;

use App\Platform\Entity\Enum\WebhookStatusEnum;
use App\Platform\Input\UpdateWebhookInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Форма изменения webhook-подписки (WH-7, PATCH /webhooks/{id}).
 * Все поля необязательны (null = не менять). url/events/status обновляются
 * только переданные; секрет — отдельный эндпоинт /rotate-secret (WH-7).
 *
 * @extends AbstractType<UpdateWebhookInput>
 */
final class WebhookUpdateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('url', UrlType::class, [
                'property_path' => 'url',
                'required' => false,
                'default_protocol' => 'https',
                'constraints' => [new Length(max: 2048)],
            ])
            ->add('events', CollectionType::class, [
                'property_path' => 'events',
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
            ->add('status', ChoiceType::class, [
                'property_path' => 'status',
                'required' => false,
                'choices' => WebhookStatusEnum::getValues(),
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => UpdateWebhookInput::class,
        ]);
    }
}

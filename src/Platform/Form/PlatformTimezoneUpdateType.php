<?php

declare(strict_types=1);

namespace App\Platform\Form;

use App\Platform\Input\PlatformTimezoneUpdateInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Форма установки доменного часового пояса (PUT /platform/timezone).
 * Валидация JSON-тела — здесь (NotBlank), корректность IANA-идентификатора —
 * в PlatformSettingsService::setTimezone (422).
 *
 * @extends AbstractType<PlatformTimezoneUpdateInput>
 */
final class PlatformTimezoneUpdateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('timezone_default', TextType::class, [
                'property_path' => 'timezoneDefault',
                'empty_data' => '',
                'constraints' => [new NotBlank(message: 'timezone_default is required')],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => PlatformTimezoneUpdateInput::class,
        ]);
    }
}

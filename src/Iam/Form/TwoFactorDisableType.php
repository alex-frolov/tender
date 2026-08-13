<?php

declare(strict_types=1);

namespace App\Iam\Form;

use App\Iam\Input\TwoFactorDisableInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Форма отключения 2FA (FR-1.5.3): code обязателен (подтверждение владения).
 * Валидация входных данных JSON-тела POST /auth/2fa/disable — здесь,
 * а не в контроллере.
 *
 * @extends AbstractType<TwoFactorDisableInput>
 */
final class TwoFactorDisableType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'empty_data' => '',
                'constraints' => [new NotBlank()],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => TwoFactorDisableInput::class,
        ]);
    }
}

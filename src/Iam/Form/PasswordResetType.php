<?php

declare(strict_types=1);

namespace App\Iam\Form;

use App\Iam\Input\ResetPasswordInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Форма сброса пароля (FR-1.5.6): token + new_password обязательны.
 * Валидация входных данных JSON-тела POST /auth/password/reset — здесь,
 * а не в контроллере.
 *
 * @extends AbstractType<ResetPasswordInput>
 */
final class PasswordResetType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('token', TextType::class, [
                'empty_data' => '',
                'constraints' => [new NotBlank()],
            ])
            ->add('new_password', TextType::class, [
                'property_path' => 'newPassword',
                'empty_data' => '',
                'constraints' => [new NotBlank()],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => ResetPasswordInput::class,
        ]);
    }
}

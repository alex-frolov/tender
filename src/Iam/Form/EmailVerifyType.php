<?php

declare(strict_types=1);

namespace App\Iam\Form;

use App\Iam\Input\VerifyEmailInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Форма подтверждения email (FR-1.5.5): token обязателен.
 * Валидация входных данных JSON-тела POST /auth/email/verify — здесь,
 * а не в контроллере.
 *
 * @extends AbstractType<VerifyEmailInput>
 */
final class EmailVerifyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('token', TextType::class, [
                'empty_data' => '',
                'constraints' => [new NotBlank()],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => VerifyEmailInput::class,
        ]);
    }
}

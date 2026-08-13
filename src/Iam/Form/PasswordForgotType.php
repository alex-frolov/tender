<?php

declare(strict_types=1);

namespace App\Iam\Form;

use App\Iam\Input\ForgotPasswordInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Форма запроса восстановления пароля (FR-1.5.6): email обязателен.
 * Валидация входных данных JSON-тела POST /auth/password/forgot — здесь,
 * а не в контроллере.
 *
 * @extends AbstractType<ForgotPasswordInput>
 */
final class PasswordForgotType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', TextType::class, [
                'empty_data' => '',
                'constraints' => [new NotBlank(), new Email()],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => ForgotPasswordInput::class,
        ]);
    }
}

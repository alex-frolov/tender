<?php

declare(strict_types=1);

namespace App\Iam\Form;

use App\Iam\Input\ResendEmailInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Форма повторной отправки письма подтверждения email (FR-1.5.5): email
 * обязателен. Валидация входных данных JSON-тела POST /auth/email/resend —
 * здесь, а не в контроллере.
 *
 * @extends AbstractType<ResendEmailInput>
 */
final class EmailResendType extends AbstractType
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
            'data_class' => ResendEmailInput::class,
        ]);
    }
}

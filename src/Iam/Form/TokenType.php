<?php

declare(strict_types=1);

namespace App\Iam\Form;

use App\Iam\Input\TokenInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Форма аутентификации (FR-1.5.3): email + password обязательны, totp_code
 * опционален. Валидация входных данных JSON-тела POST /auth/token — здесь,
 * а не в контроллере.
 *
 * @extends AbstractType<TokenInput>
 */
final class TokenType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', TextType::class, [
                'empty_data' => '',
                'constraints' => [new NotBlank(), new Email()],
            ])
            ->add('password', TextType::class, [
                'empty_data' => '',
                'constraints' => [new NotBlank()],
            ])
            ->add('totp_code', TextType::class, [
                'property_path' => 'totpCode',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => TokenInput::class,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Iam\Form;

use App\Iam\Input\RefreshInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Форма ротации refresh-токена (FR-1.5.3): refresh_token обязателен.
 * Валидация входных данных JSON-тела POST /auth/refresh — здесь,
 * а не в контроллере.
 *
 * @extends AbstractType<RefreshInput>
 */
final class RefreshType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('refresh_token', TextType::class, [
                'property_path' => 'refreshToken',
                'empty_data' => '',
                'constraints' => [new NotBlank()],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => RefreshInput::class,
        ]);
    }
}

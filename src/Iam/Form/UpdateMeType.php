<?php

declare(strict_types=1);

namespace App\Iam\Form;

use App\Iam\Input\UpdateMeInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Форма обновления своего профиля (FR-1.5.8, PATCH /users/me).
 * Поля опциональны: name, current_password, new_password. Валидация входных
 * данных JSON-тела — здесь, а не в контроллере.
 *
 * @extends AbstractType<UpdateMeInput>
 */
final class UpdateMeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'required' => false,
                'constraints' => [new Length(max: 200)],
            ])
            ->add('current_password', PasswordType::class, [
                'property_path' => 'currentPassword',
                'required' => false,
            ])
            ->add('new_password', PasswordType::class, [
                'property_path' => 'newPassword',
                'required' => false,
                'constraints' => [new Length(min: 8)],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => UpdateMeInput::class,
        ]);
    }
}

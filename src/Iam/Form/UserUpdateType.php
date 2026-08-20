<?php

declare(strict_types=1);

namespace App\Iam\Form;

use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Entity\Enum\UserStatusEnum;
use App\Iam\Input\UpdateUserInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Форма обновления пользователя (FR-1.5.8): смена имени, роли и/или статуса.
 * Все поля опциональны — применяются только указанные. Валидация входных данных
 * JSON-тела PATCH /users/{userId} — здесь, а не в контроллере.
 *
 * @extends AbstractType<UpdateUserInput>
 */
final class UserUpdateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'required' => false,
                'constraints' => [new Length(max: 200)],
            ])
            ->add('role', ChoiceType::class, [
                'choices' => [
                    'admin' => UserRoleEnum::ADMIN->value,
                    'manager' => UserRoleEnum::MANAGER->value,
                    'agent' => UserRoleEnum::AGENT->value,
                ],
                'required' => false,
            ])
            ->add('status', ChoiceType::class, [
                'choices' => [
                    'active' => UserStatusEnum::ACTIVE->value,
                    'blocked' => UserStatusEnum::BLOCKED->value,
                ],
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => UpdateUserInput::class,
        ]);
    }
}

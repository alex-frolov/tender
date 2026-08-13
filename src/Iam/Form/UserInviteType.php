<?php

declare(strict_types=1);

namespace App\Iam\Form;

use App\Iam\Entity\Enum\UserRoleEnum;
use App\Iam\Input\InviteUserInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Форма приглашения сотрудника (FR-1.5.8): email + имя обязательны,
 * роль опциональна (по умолчанию agent). Валидация входных данных JSON-тела
 * POST /users — здесь, а не в контроллере. platform_admin недоступен через API.
 *
 * @extends AbstractType<InviteUserInput>
 */
final class UserInviteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', TextType::class, [
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(),
                    new Email(),
                ],
            ])
            ->add('name', TextType::class, [
                'empty_data' => '',
                'constraints' => [new NotBlank()],
            ])
            ->add('role', ChoiceType::class, [
                'choices' => UserRoleEnum::getCompanyRoleValues(),
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => InviteUserInput::class,
        ]);
    }
}

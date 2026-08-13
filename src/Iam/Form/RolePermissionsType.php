<?php

declare(strict_types=1);

namespace App\Iam\Form;

use App\Iam\Input\RolePermissionsInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Форма обновления набора прав роли (FR-1.5.15).
 * Валидирует role (manager/agent). Динамическая карта permissions
 * (code → enabled) обрабатывается в сервисе — см. RolePermissionsInput.
 *
 * @extends AbstractType<RolePermissionsInput>
 */
final class RolePermissionsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('role', ChoiceType::class, [
                'empty_data' => '',
                'choices' => [
                    'manager' => 'manager',
                    'agent' => 'agent',
                ],
                'constraints' => [new NotBlank()],
            ])
        ;
        $builder->get('role')->addModelTransformer(new CallbackTransformer(
            static fn (?string $value): string => $value ?? '',
            static fn (?string $value): string => $value ?? '',
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => RolePermissionsInput::class,
            // динамическая карта permissions (code → enabled) не объявляется
            // статическими полями формы — она валидируется в сервисе
            'allow_extra_fields' => true,
        ]);
    }
}

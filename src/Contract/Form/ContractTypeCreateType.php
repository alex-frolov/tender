<?php

declare(strict_types=1);

namespace App\Contract\Form;

use App\Contract\Input\CreateContractTypeInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Форма создания типа договора (FR-1.4.3, POST /contract-types).
 * code/name — обязательные (openapi); is_single_use — флаг области действия
 * по умолчанию (false → multi_use); template_ref в ядре не хранится.
 *
 * @extends AbstractType<CreateContractTypeInput>
 */
final class ContractTypeCreateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(),
                    new Length(max: 50),
                ],
            ])
            ->add('name', TextType::class, [
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(),
                    new Length(max: 200),
                ],
            ])
            ->add('is_single_use', CheckboxType::class, [
                'property_path' => 'isSingleUse',
                'required' => false,
            ])
            ->add('template_ref', TextType::class, [
                'property_path' => 'templateRef',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => CreateContractTypeInput::class,
        ]);
    }
}

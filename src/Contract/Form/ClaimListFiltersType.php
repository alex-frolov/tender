<?php

declare(strict_types=1);

namespace App\Contract\Form;

use App\Contract\Entity\Enum\ClaimStatusEnum;
use App\Contract\Input\ClaimListFiltersInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Uuid as UuidConstraint;

/**
 * Фильтры списка претензий (GET /claims): договор и статус — оба необязательны.
 * Невалидный uuid или неизвестный статус → 422 (ValidationException).
 *
 * @extends AbstractType<ClaimListFiltersInput>
 */
final class ClaimListFiltersType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('contract_id', TextType::class, [
                'required' => false,
                'property_path' => 'contractId',
                'constraints' => [new UuidConstraint()],
            ])
            ->add('status', ChoiceType::class, [
                'required' => false,
                'choices' => ClaimStatusEnum::getValues(),
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => ClaimListFiltersInput::class,
        ]);
    }
}

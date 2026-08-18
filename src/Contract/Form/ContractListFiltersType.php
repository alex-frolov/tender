<?php

declare(strict_types=1);

namespace App\Contract\Form;

use App\Contract\Entity\Enum\ContractStatusEnum;
use App\Contract\Input\ContractListFiltersInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Фильтры списка договоров (GET /contracts, query-параметр contract_status).
 *
 * Необязательный фильтр по статусу договора; enum-значения валидируются
 * ChoiceType (невалидное → 422). data_class — App\Contract\Input\ContractListFiltersInput.
 *
 * @extends AbstractType<ContractListFiltersInput>
 */
final class ContractListFiltersType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('contract_status', ChoiceType::class, [
                'required' => false,
                'property_path' => 'contractStatus',
                'choices' => ContractStatusEnum::getValues(),
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => ContractListFiltersInput::class,
        ]);
    }
}

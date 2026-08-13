<?php

declare(strict_types=1);

namespace App\Contract\Form;

use App\Contract\Entity\Enum\ContractScopeEnum;
use App\Contract\Entity\Enum\ContractSourceEnum;
use App\Contract\Input\CreateContractInput;
use App\Tender\Entity\Enum\PriceBasisEnum;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Форма заключения рамочного договора (FR-1.4.8, POST /contracts).
 * Имена полей — snake_case (openapi ContractCreate). Обязательные: contract_type_id,
 * customer_id, supplier_id, scope; source по умолчанию external (рамочный),
 * source=tender — по итогам тендера. Валидация enum/диапазонов — здесь;
 * преобразование в доменные типы и бизнес-правила — в ContractService.
 *
 * @extends AbstractType<CreateContractInput>
 */
final class ContractCreateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('contract_type_id', TextType::class, [
                'property_path' => 'contractTypeId',
                'empty_data' => '',
                'constraints' => [new NotBlank()],
            ])
            ->add('source', ChoiceType::class, [
                'required' => false,
                'placeholder' => ContractSourceEnum::EXTERNAL->value,
                'choices' => ContractSourceEnum::getValues(),
            ])
            ->add('customer_id', TextType::class, [
                'property_path' => 'customerId',
                'empty_data' => '',
                'constraints' => [new NotBlank()],
            ])
            ->add('supplier_id', TextType::class, [
                'property_path' => 'supplierId',
                'empty_data' => '',
                'constraints' => [new NotBlank()],
            ])
            ->add('scope', ChoiceType::class, [
                'empty_data' => '',
                'constraints' => [new NotBlank()],
                'choices' => ContractScopeEnum::getValues(),
            ])
            ->add('tender_id', TextType::class, [
                'property_path' => 'tenderId',
                'required' => false,
            ])
            ->add('price_net_minor', IntegerType::class, [
                'property_path' => 'priceNetMinor',
                'required' => false,
                'constraints' => [new Range(min: 0)],
            ])
            ->add('vat_rate', NumberType::class, [
                'property_path' => 'vatRate',
                'required' => false,
                'scale' => 4,
            ])
            ->add('price_basis', ChoiceType::class, [
                'property_path' => 'priceBasis',
                'required' => false,
                'choices' => PriceBasisEnum::getValues(),
            ])
            ->add('valid_from', TextType::class, [
                'property_path' => 'validFrom',
                'required' => false,
                'constraints' => [new Length(max: 30)],
            ])
            ->add('valid_to', TextType::class, [
                'property_path' => 'validTo',
                'required' => false,
                'constraints' => [new Length(max: 30)],
            ])
            ->add('terms', CollectionType::class, [
                'required' => false,
                'allow_add' => true,
                'entry_type' => TextType::class,
            ])
        ;
        $builder->get('scope')->addModelTransformer(new CallbackTransformer(
            static fn (?string $value): string => $value ?? '',
            static fn (?string $value): string => $value ?? '',
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => CreateContractInput::class,
        ]);
    }
}

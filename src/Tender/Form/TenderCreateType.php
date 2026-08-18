<?php

declare(strict_types=1);

namespace App\Tender\Form;

use App\Tender\Entity\Enum\AccessTypeEnum;
use App\Tender\Entity\Enum\LawTypeEnum;
use App\Tender\Entity\Enum\PriceBasisEnum;
use App\Tender\Entity\Enum\ProcedureTypeEnum;
use App\Tender\Input\CreateTenderInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Форма создания тендера (FR-1.1.1). Тендер создаётся в статусе draft.
 * Имена полей — snake_case (как в openapi TenderCreate); маппинг на camelCase
 * свойства DTO — через property_path. Обязательные поля: title, procedure_type,
 * customer_id, currency, price_basis и массив lots (минимум 1). Валидация
 * enum/диапазонов — здесь; преобразование в доменные типы и генерация number
 * — в TenderService.
 *
 * @extends AbstractType<CreateTenderInput>
 */
final class TenderCreateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(),
                    new Length(max: 500),
                ],
            ])
            ->add('description', TextType::class, [
                'required' => false,
                'constraints' => [new Length(max: 10000)],
            ])
            ->add('procedure_type', ChoiceType::class, [
                'property_path' => 'procedureType',
                'empty_data' => '',
                'constraints' => [new NotBlank()],
                'choices' => ProcedureTypeEnum::getValues(),
            ])
            ->add('law_type', ChoiceType::class, [
                'property_path' => 'lawType',
                'required' => false,
                'placeholder' => LawTypeEnum::COMMERCIAL->value,
                'choices' => LawTypeEnum::getValues(),
            ])
            ->add('nmck_minor', IntegerType::class, [
                'property_path' => 'nmckMinor',
                'required' => false,
                'constraints' => [new Range(min: 0)],
            ])
            ->add('no_start_price', CheckboxType::class, [
                'property_path' => 'noStartPrice',
                'required' => false,
            ])
            ->add('currency', TextType::class, [
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(),
                    new Length(exactly: 3),
                ],
            ])
            ->add('vat_rate', NumberType::class, [
                'property_path' => 'vatRate',
                'required' => false,
                'scale' => 4,
            ])
            ->add('price_basis', ChoiceType::class, [
                'property_path' => 'priceBasis',
                'empty_data' => '',
                'constraints' => [new NotBlank()],
                'choices' => PriceBasisEnum::getValues(),
            ])
            ->add('customer_id', TextType::class, [
                'property_path' => 'customerId',
                'empty_data' => '',
                'constraints' => [new NotBlank()],
            ])
            ->add('region', TextType::class, [
                'required' => false,
                'constraints' => [new Length(max: 100)],
            ])
            ->add('okpd2', TextType::class, [
                'required' => false,
                'constraints' => [new Length(max: 20)],
            ])
            ->add('access_type', ChoiceType::class, [
                'property_path' => 'accessType',
                'required' => false,
                'placeholder' => AccessTypeEnum::OPEN->value,
                'choices' => AccessTypeEnum::getValues(),
            ])
            ->add('required_contract_type_id', TextType::class, [
                'property_path' => 'requiredContractTypeId',
                'required' => false,
            ])
            ->add('timeline', CollectionType::class, [
                'required' => false,
                'allow_add' => true,
                'entry_type' => TextType::class,
            ])
            ->add('lots', CollectionType::class, [
                'entry_type' => LotType::class,
                'allow_add' => true,
                'constraints' => [new Count(min: 1)],
            ])
        ;
        $builder->get('procedure_type')->addModelTransformer(new CallbackTransformer(
            static fn (?string $value): string => $value ?? '',
            static fn (?string $value): string => $value ?? '',
        ));
        $builder->get('price_basis')->addModelTransformer(new CallbackTransformer(
            static fn (?string $value): string => $value ?? '',
            static fn (?string $value): string => $value ?? '',
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => CreateTenderInput::class,
        ]);
    }
}

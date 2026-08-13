<?php

declare(strict_types=1);

namespace App\Tender\Form;

use App\Tender\Entity\Enum\PriceBasisEnum;
use App\Tender\Input\LotInput;
use Symfony\Component\Form\AbstractType;
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
 * Форма лота при создании тендера (FR-1.1.7). Обязательные поля: title,
 * price_net_minor. vat_rate/price_basis необязательны — наследуются от тендера
 * в TenderService. Имена полей — snake_case (как в openapi LotCreate); маппинг
 * на camelCase свойства DTO — через property_path. Элемент массива lots.
 *
 * @extends AbstractType<LotInput>
 */
final class LotType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('number', IntegerType::class, [
                'required' => false,
                'constraints' => [new Range(min: 1)],
            ])
            ->add('title', TextType::class, [
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(),
                    new Length(max: 500),
                ],
            ])
            ->add('price_net_minor', IntegerType::class, [
                'property_path' => 'priceNetMinor',
                'constraints' => [
                    new NotBlank(),
                    new Range(min: 0),
                ],
            ])
            ->add('vat_rate', NumberType::class, [
                'property_path' => 'vatRate',
                'required' => false,
            ])
            ->add('price_basis', ChoiceType::class, [
                'property_path' => 'priceBasis',
                'required' => false,
                'choices' => PriceBasisEnum::getValues(),
            ])
            ->add('quantity', NumberType::class, ['required' => false])
            ->add('unit', TextType::class, [
                'required' => false,
                'constraints' => [new Length(max: 50)],
            ])
            ->add('delivery_terms', CollectionType::class, [
                'property_path' => 'deliveryTerms',
                'required' => false,
                'allow_add' => true,
                'entry_type' => TextType::class,
            ])
            ->add('execution_start_at', TextType::class, [
                'property_path' => 'executionStartAt',
                'required' => false,
            ])
            ->add('trade_end_lead_hours', IntegerType::class, [
                'property_path' => 'tradeEndLeadHours',
                'required' => false,
                'constraints' => [new Range(min: 0)],
            ])
            ->add('security_percent', NumberType::class, [
                'property_path' => 'securityPercent',
                'required' => false,
                'constraints' => [new Range(min: 0, max: 100)],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => LotInput::class,
        ]);
    }
}

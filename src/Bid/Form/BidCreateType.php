<?php

declare(strict_types=1);

namespace App\Bid\Form;

use App\Bid\Input\CreateBidInput;
use App\Tender\Entity\Enum\PriceBasisEnum;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Uuid;

/**
 * Форма подачи/замены заявки (FR-1.2.1/1.2.5, POST /tenders/{tenderId}/bids).
 * supplier_id — компания-исполнитель (обязателен, сверяется с актором в сервисе);
 * lot_id — необязателен; part1 — свободный JSON-объект (согласие, характеристики);
 * part2_document_ids — список id документов (часть 2); price_minor/price_basis/
 * vat_rate — цена для конкурсных процедур. Имена полей — snake_case (openapi
 * BidCreate), маппинг на camelCase DTO — через property_path.
 *
 * @extends AbstractType<CreateBidInput>
 */
final class BidCreateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('supplier_id', TextType::class, [
                'property_path' => 'supplierId',
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(message: 'supplier_id is required'),
                    new Uuid(message: 'supplier_id must be a valid UUID'),
                ],
            ])
            ->add('lot_id', TextType::class, [
                'property_path' => 'lotId',
                'required' => false,
                'constraints' => [new Uuid(message: 'lot_id must be a valid UUID')],
            ])
            ->add('part1', JsonObjectType::class, [
                'required' => false,
            ])
            ->add('part2_document_ids', CollectionType::class, [
                'property_path' => 'part2DocumentIds',
                'required' => false,
                'allow_add' => true,
                'entry_type' => TextType::class,
                'entry_options' => [
                    'constraints' => [new Uuid(message: 'document id must be a valid UUID')],
                ],
            ])
            ->add('price_minor', IntegerType::class, [
                'property_path' => 'priceMinor',
                'required' => false,
                'constraints' => [new Range(min: 0)],
            ])
            ->add('price_basis', ChoiceType::class, [
                'property_path' => 'priceBasis',
                'required' => false,
                'choices' => PriceBasisEnum::getValues(),
            ])
            ->add('vat_rate', NumberType::class, [
                'property_path' => 'vatRate',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => CreateBidInput::class,
        ]);
    }
}

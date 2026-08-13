<?php

declare(strict_types=1);

namespace App\Contract\Form;

use App\Contract\Input\BindTenderInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Uuid;

/**
 * Форма привязки тендера к договору (FR-1.4.6, POST /contracts/{id}/tenders).
 * Обязательный: tender_id; lot_id/award_id — опционально (по тендеру);
 * price_net_minor — цена по этому тендеру.
 *
 * @extends AbstractType<BindTenderInput>
 */
final class BindTenderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('tender_id', TextType::class, [
                'property_path' => 'tenderId',
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(message: 'tender_id is required'),
                    new Uuid(message: 'tender_id must be a valid UUID'),
                ],
            ])
            ->add('lot_id', TextType::class, [
                'property_path' => 'lotId',
                'required' => false,
                'constraints' => [new Uuid(message: 'lot_id must be a valid UUID')],
            ])
            ->add('award_id', TextType::class, [
                'property_path' => 'awardId',
                'required' => false,
                'constraints' => [new Uuid(message: 'award_id must be a valid UUID')],
            ])
            ->add('price_net_minor', IntegerType::class, [
                'property_path' => 'priceNetMinor',
                'constraints' => [new Range(min: 0)],
            ])
            ->add('vat_rate', NumberType::class, [
                'property_path' => 'vatRate',
                'required' => false,
                'scale' => 4,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => BindTenderInput::class,
        ]);
    }
}

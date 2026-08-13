<?php

declare(strict_types=1);

namespace App\Auction\Form;

use App\Auction\Input\PlaceAuctionBidInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Форма подачи ставки в аукционе (FR-1.3.2, POST /auctions/{id}/bids).
 * price_minor — обязательная цена в minor units (PR-1): неотрицательное целое;
 * механика шага/понижения/границ определяется типом аукциона в сервисе.
 * Имя поля — snake_case (openapi AuctionBidCreate).
 *
 * @extends AbstractType<PlaceAuctionBidInput>
 */
final class AuctionBidType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('price_minor', IntegerType::class, [
                'property_path' => 'priceMinor',
                'constraints' => [
                    new NotBlank(message: 'price_minor is required'),
                    new Range(min: 0, minMessage: 'price_minor must be >= 0 (minor units, PR-1)'),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => PlaceAuctionBidInput::class,
        ]);
    }
}

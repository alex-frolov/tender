<?php

declare(strict_types=1);

namespace App\Auction\Form;

use App\Auction\Input\SelectWinnerInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Uuid;

/**
 * Форма выбора победителя аукциона (FR-1.3.5, POST /auctions/{id}/winner).
 * bid_id — опциональный id принятой ставки (auction_bids.id):
 * ручной выбор (FREE_PRICE/PRICE_REQUEST) требует его; авто-выбор (REDUCTION)
 * может обойтись без него (минимальная цена). Невалидный UUID → 422.
 *
 * @extends AbstractType<SelectWinnerInput>
 */
final class AuctionWinnerType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('bid_id', TextType::class, [
                'property_path' => 'bidId',
                'required' => false,
                'constraints' => [
                    new Uuid(),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => SelectWinnerInput::class,
        ]);
    }
}

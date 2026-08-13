<?php

declare(strict_types=1);

namespace App\Auction\Form;

use App\Auction\Input\CancelAuctionInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Форма отмены аукциона (T7/T9/T12/T14/T19/T22/T25/T28/T32,
 * POST /auctions/{id}/cancel). reason — необязательный текст причины
 * (в аудит и событие auction.cancelled).
 *
 * @extends AbstractType<CancelAuctionInput>
 */
final class AuctionCancelType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('reason', TextType::class, [
                'required' => false,
                'constraints' => [new Length(max: 500, maxMessage: 'reason must be at most 500 characters')],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => CancelAuctionInput::class,
        ]);
    }
}

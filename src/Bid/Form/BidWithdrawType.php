<?php

declare(strict_types=1);

namespace App\Bid\Form;

use App\Bid\Input\WithdrawBidInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Форма отзыва заявки (FR-1.2.5, POST /bids/{bidId}/withdraw).
 * reason — обязательная причина отзыва (до 500 символов), сохраняется
 * в decision_reason и аудите (AM-4).
 *
 * @extends AbstractType<WithdrawBidInput>
 */
final class BidWithdrawType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('reason', TextType::class, [
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(),
                    new Length(max: 500),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => WithdrawBidInput::class,
        ]);
    }
}

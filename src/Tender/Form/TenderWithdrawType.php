<?php

declare(strict_types=1);

namespace App\Tender\Form;

use App\Tender\Input\WithdrawTenderInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Форма отзыва публикации (B3, POST /tenders/{tenderId}/withdraw).
 * reason — обязательный свободный текст причины отзыва (до 500 символов).
 * Отзыв допустим только до старта приёма заявок (published → withdrawn).
 *
 * @extends AbstractType<WithdrawTenderInput>
 */
final class TenderWithdrawType extends AbstractType
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
            'data_class' => WithdrawTenderInput::class,
        ]);
    }
}

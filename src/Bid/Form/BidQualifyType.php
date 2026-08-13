<?php

declare(strict_types=1);

namespace App\Bid\Form;

use App\Bid\Entity\Enum\BidDecisionEnum;
use App\Bid\Input\QualifyBidInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Форма решения по заявке (FR-1.2.4, POST /bids/{bidId}/qualification).
 * decision — admit|reject; reason — обязательная причина (до 1000 символов),
 * сохраняется в decision_reason и аудите; при отклонении уведомляется участник.
 *
 * @extends AbstractType<QualifyBidInput>
 */
final class BidQualifyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('decision', ChoiceType::class, [
                'empty_data' => '',
                'choices' => BidDecisionEnum::getValues(),
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            ->add('reason', TextType::class, [
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(),
                    new Length(max: 1000),
                ],
            ])
        ;
        $builder->get('decision')->addModelTransformer(new CallbackTransformer(
            static fn (?string $value): string => $value ?? '',
            static fn (?string $value): string => $value ?? '',
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => QualifyBidInput::class,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Contract\Form;

use App\Contract\Input\ResolveClaimInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Форма урегулирования претензии (FR-1.4.5, POST /claims/{claimId}/resolve).
 * outcome: rejected/settled/accepted/terminate_contract.
 *
 * @extends AbstractType<ResolveClaimInput>
 */
final class ResolveClaimType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('outcome', ChoiceType::class, [
                'property_path' => 'outcome',
                'empty_data' => '',
                'choices' => [
                    'rejected' => 'rejected',
                    'settled' => 'settled',
                    'accepted' => 'accepted',
                    'terminate_contract' => 'terminate_contract',
                ],
                'constraints' => [new NotBlank(message: 'outcome is required')],
            ])
            ->add('resolution', TextType::class, [
                'property_path' => 'resolution',
                'required' => false,
            ])
            ->add('accepted_amount_minor', IntegerType::class, [
                'property_path' => 'acceptedAmountMinor',
                'required' => false,
                'constraints' => [new Range(min: 0)],
            ])
        ;
        $builder->get('outcome')->addModelTransformer(new CallbackTransformer(
            static fn (?string $value): string => $value ?? '',
            static fn (?string $value): string => $value ?? '',
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => ResolveClaimInput::class,
        ]);
    }
}

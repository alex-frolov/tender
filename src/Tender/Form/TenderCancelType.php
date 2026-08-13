<?php

declare(strict_types=1);

namespace App\Tender\Form;

use App\Tender\Entity\Enum\CancellationReasonEnum;
use App\Tender\Input\CancelTenderInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Форма отмены тендера (FR-1.1.8, POST /tenders/{tenderId}/cancel).
 * cancellation_reason_code — обязательный код причины из CancellationReasonEnum;
 * cancellation_reason_text — свободный текст, обязателен при code=other
 * (cross-field правило проверяется в TenderService::cancel).
 *
 * @extends AbstractType<CancelTenderInput>
 */
final class TenderCancelType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('cancellation_reason_code', ChoiceType::class, [
                'property_path' => 'cancellationReasonCode',
                'empty_data' => '',
                'constraints' => [new NotBlank()],
                'choices' => CancellationReasonEnum::getValues(),
            ])
            ->add('cancellation_reason_text', TextType::class, [
                'property_path' => 'cancellationReasonText',
                'required' => false,
                'constraints' => [new Length(max: 2000)],
            ])
        ;
        $builder->get('cancellation_reason_code')->addModelTransformer(new CallbackTransformer(
            static fn (?string $value): string => $value ?? '',
            static fn (?string $value): string => $value ?? '',
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => CancelTenderInput::class,
        ]);
    }
}

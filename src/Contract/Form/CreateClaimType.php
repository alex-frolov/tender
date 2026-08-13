<?php

declare(strict_types=1);

namespace App\Contract\Form;

use App\Contract\Entity\Enum\ClaimStageEnum;
use App\Contract\Input\CreateClaimInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Uuid;

/**
 * Форма создания претензии (FR-1.4.5, POST /claims). Обязательные: contract_id,
 * stage, reason, amount_minor; document_ids — опционально.
 *
 * @extends AbstractType<CreateClaimInput>
 */
final class CreateClaimType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('contract_id', TextType::class, [
                'property_path' => 'contractId',
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(message: 'contract_id is required'),
                    new Uuid(message: 'contract_id must be a valid UUID'),
                ],
            ])
            ->add('stage', ChoiceType::class, [
                'property_path' => 'stage',
                'empty_data' => '',
                'choices' => ClaimStageEnum::getValues(),
                'constraints' => [new NotBlank(message: 'stage is required')],
            ])
            ->add('reason', TextType::class, [
                'property_path' => 'reason',
                'empty_data' => '',
                'constraints' => [new NotBlank(message: 'reason is required')],
            ])
            ->add('description', TextType::class, [
                'property_path' => 'description',
                'required' => false,
            ])
            ->add('amount_minor', IntegerType::class, [
                'property_path' => 'amountMinor',
                'constraints' => [new Range(min: 0)],
            ])
            ->add('document_ids', CollectionType::class, [
                'property_path' => 'documentIds',
                'required' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_type' => TextType::class,
                'entry_options' => ['constraints' => [new Uuid(message: 'document_id must be a valid UUID')]],
            ])
        ;
        $builder->get('stage')->addModelTransformer(new CallbackTransformer(
            static fn (?string $value): string => $value ?? '',
            static fn (?string $value): string => $value ?? '',
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => CreateClaimInput::class,
        ]);
    }
}

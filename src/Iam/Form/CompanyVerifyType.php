<?php

declare(strict_types=1);

namespace App\Iam\Form;

use App\Iam\Entity\Enum\CompanyStatusTransition;
use App\Iam\Input\CompanyVerifyInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Форма модерации компании (FR-1.5.7): action — ChoiceType через enum
 * CompanyStatusTransition (approve/reject/suspend); reason опционален —
 * обязательность для reject остаётся в CompanyVerificationService.
 * Валидация входных данных JSON-тела POST /companies/{companyId}/verify — здесь,
 * а не в контроллере.
 *
 * @extends AbstractType<CompanyVerifyInput>
 */
final class CompanyVerifyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('action', ChoiceType::class, [
                'empty_data' => '',
                'choices' => CompanyStatusTransition::getValues(),
                'constraints' => [new NotBlank()],
            ])
            ->add('reason', TextType::class, [
                'required' => false,
            ])
        ;
        $builder->get('action')->addModelTransformer(new CallbackTransformer(
            static fn (?string $value): string => $value ?? '',
            static fn (?string $value): string => $value ?? '',
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => CompanyVerifyInput::class,
        ]);
    }
}

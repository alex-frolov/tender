<?php

declare(strict_types=1);

namespace App\Tender\Form;

use App\Tender\Input\RateTenderInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Форма оценки исполнения (FR-1.1.10, POST /tenders/{tenderId}/rating).
 * execution_rating — int 1..10 (nullable: сброс). Бизнес-правило «только после
 * DONE/DONE_BY_CLAIM» — в TenderService (rating_not_allowed).
 *
 * @extends AbstractType<RateTenderInput>
 */
final class TenderRateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('execution_rating', IntegerType::class, [
                'property_path' => 'executionRating',
                'required' => false,
                'constraints' => [new Range(min: 1, max: 10)],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => RateTenderInput::class,
        ]);
    }
}

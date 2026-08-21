<?php

declare(strict_types=1);

namespace App\Complaint\Form;

use App\Complaint\Entity\Enum\ComplaintStatusEnum;
use App\Complaint\Input\ComplaintListFiltersInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Uuid as UuidConstraint;

/**
 * Фильтры списка жалоб (GET /complaints): тендер и статус — оба необязательны.
 * Невалидный uuid или неизвестный статус → 422 (ValidationException).
 *
 * @extends AbstractType<ComplaintListFiltersInput>
 */
final class ComplaintListFiltersType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('tender_id', TextType::class, [
                'required' => false,
                'property_path' => 'tenderId',
                'constraints' => [new UuidConstraint()],
            ])
            ->add('status', ChoiceType::class, [
                'required' => false,
                'choices' => ComplaintStatusEnum::getValues(),
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => ComplaintListFiltersInput::class,
        ]);
    }
}

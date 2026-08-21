<?php

declare(strict_types=1);

namespace App\Document\Form;

use App\Document\Entity\Enum\DocumentEntityType;
use App\Document\Input\DocumentListFiltersInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Uuid as UuidConstraint;

/**
 * Фильтры списка документов (GET /documents). Оба параметра обязательны:
 * документы отдаются только по конкретной сущности. Неизвестный тип или
 * невалидный uuid → 422.
 *
 * @extends AbstractType<DocumentListFiltersInput>
 */
final class DocumentListFiltersType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('entity_type', ChoiceType::class, [
                'property_path' => 'entityType',
                'empty_data' => '',
                'choices' => DocumentEntityType::getValues(),
                'constraints' => [new NotBlank(message: 'entity_type is required')],
            ])
            ->add('entity_id', TextType::class, [
                'property_path' => 'entityId',
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(message: 'entity_id is required'),
                    new UuidConstraint(),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => DocumentListFiltersInput::class,
        ]);
    }
}

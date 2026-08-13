<?php

declare(strict_types=1);

namespace App\Document\Form;

use App\Document\Entity\Enum\DocumentVisibility;
use App\Document\Input\UpdateDocumentTypeInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Форма изменения типа документа (FR-1.2.7, PUT /document-types/{id}, суперадмин).
 * Все поля необязательны (null = не менять); active=false — деактивация.
 *
 * @extends AbstractType<UpdateDocumentTypeInput>
 */
final class UpdateDocumentTypeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'required' => false,
                'constraints' => [new Length(max: 200)],
            ])
            ->add('owner_role', ChoiceType::class, [
                'property_path' => 'ownerRole',
                'choices' => ['customer' => 'customer', 'executor' => 'executor', 'both' => 'both'],
                'required' => false,
            ])
            ->add('visibility', ChoiceType::class, [
                'choices' => DocumentVisibility::getValues(),
                'required' => false,
            ])
            ->add('required', CheckboxType::class, [
                'required' => false,
            ])
            ->add('active', CheckboxType::class, [
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => UpdateDocumentTypeInput::class,
        ]);
    }
}

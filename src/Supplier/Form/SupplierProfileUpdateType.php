<?php

declare(strict_types=1);

namespace App\Supplier\Form;

use App\Supplier\Input\SupplierProfileUpdateInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Форма обновления профиля поставщика (FR-1.5.5, PUT /suppliers/profile).
 * Все поля — коллекции строк (categories/capabilities/documents); отсутствие
 * поля в JSON-теле = пустой массив (очистка). Валидация — здесь.
 *
 * @extends AbstractType<SupplierProfileUpdateInput>
 */
final class SupplierProfileUpdateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('categories', CollectionType::class, [
                'required' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_type' => TextType::class,
            ])
            ->add('capabilities', CollectionType::class, [
                'required' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_type' => TextType::class,
            ])
            ->add('documents', CollectionType::class, [
                'required' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_type' => TextType::class,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => SupplierProfileUpdateInput::class,
        ]);
    }
}

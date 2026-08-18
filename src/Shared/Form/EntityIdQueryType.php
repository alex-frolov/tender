<?php

declare(strict_types=1);

namespace App\Shared\Form;

use App\Shared\Input\EntityIdInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Uuid as UuidConstraint;

/**
 * Query-параметр id сущности для DELETE-эндпоинтов (?id= или ?xxxId=).
 *
 * Единая форма для удалений по query-параметру (Notification/SavedSearch/
 * Favorite): id обязателен и должен быть UUID (иначе 422). Имя поля задаётся
 * через options['id_field'] (default 'id'). data_class — App\Shared\Input\EntityIdInput.
 *
 * @extends AbstractType<EntityIdInput>
 */
final class EntityIdQueryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var string $idField */
        $idField = $options['id_field'];
        $builder
            ->add($idField, TextType::class, [
                'required' => true,
                'property_path' => 'id',
                'constraints' => [
                    new NotBlank(),
                    new UuidConstraint(message: 'id must be a valid UUID'),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => EntityIdInput::class,
            'id_field' => 'id',
        ]);
        $resolver->setAllowedTypes('id_field', 'string');
    }
}

<?php

declare(strict_types=1);

namespace App\Favorite\Form;

use App\Favorite\Entity\Enum\FavoriteEntityTypeEnum;
use App\Favorite\Input\AddFavoriteInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Uuid;

/**
 * Форма добавления записи в избранное (F-A6, POST /favorites).
 * Обязательные: entity_type (tender/lot), entity_id (uuid). note — необязателен.
 *
 * @extends AbstractType<AddFavoriteInput>
 */
final class FavoriteCreateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('entity_type', ChoiceType::class, [
                'property_path' => 'entity_type',
                'empty_data' => '',
                'choices' => FavoriteEntityTypeEnum::getValues(),
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            ->add('entity_id', TextType::class, [
                'property_path' => 'entity_id',
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(),
                    new Uuid(),
                ],
            ])
            ->add('note', TextType::class, [
                'property_path' => 'note',
                'required' => false,
                'constraints' => [
                    new Length(max: 500),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => AddFavoriteInput::class,
        ]);
    }
}

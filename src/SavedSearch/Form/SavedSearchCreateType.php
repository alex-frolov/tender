<?php

declare(strict_types=1);

namespace App\SavedSearch\Form;

use App\SavedSearch\Entity\Enum\SavedSearchDigestPeriodEnum;
use App\SavedSearch\Input\CreateSavedSearchInput;
use App\Shared\Form\WebhookFiltersType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Форма создания сохранённого поиска (F-A5, POST /saved-searches).
 * Обязательные: name, filters. digest_period — необязателен (по умолчанию
 * none). Валидация формата фильтров — WebhookFiltersType (JSON-объект).
 *
 * @extends AbstractType<CreateSavedSearchInput>
 */
final class SavedSearchCreateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'property_path' => 'name',
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(),
                    new Length(max: 200),
                ],
            ])
            ->add('filters', WebhookFiltersType::class, [
                'property_path' => 'filters',
                'required' => false,
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            ->add('digest_period', ChoiceType::class, [
                'property_path' => 'digest_period',
                'required' => false,
                'placeholder' => SavedSearchDigestPeriodEnum::NONE->value,
                'choices' => SavedSearchDigestPeriodEnum::getValues(),
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => CreateSavedSearchInput::class,
        ]);
    }
}

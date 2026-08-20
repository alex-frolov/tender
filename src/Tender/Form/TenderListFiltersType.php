<?php

declare(strict_types=1);

namespace App\Tender\Form;

use App\Tender\Input\TenderListFiltersInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Фильтры каталога тендеров (GET /tenders, query-параметры).
 *
 * Единая форма фильтрации списка: q (поиск по номеру/названию/описанию),
 * status, law_type, region (подстрока), price_min/price_max (НМЦК, minor units),
 * access_type. Enum-значения валидируются ChoiceType (невалидное → 422),
 * числовые границы — IntegerType (нечисловое → 422). Пустое поле = фильтр
 * не задан. data_class — App\Tender\Input\TenderListFiltersInput.
 *
 * @extends AbstractType<TenderListFiltersInput>
 */
final class TenderListFiltersType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('q', TextType::class, ['required' => false])
            ->add('status', ChoiceType::class, [
                'required' => false,
                'choices' => [
                    'draft' => 'draft',
                    'published' => 'published',
                    'withdrawn' => 'withdrawn',
                    'accepting_bids' => 'accepting_bids',
                    'bidding' => 'bidding',
                    'evaluation' => 'evaluation',
                    'awarding' => 'awarding',
                    'contract' => 'contract',
                    'closed' => 'closed',
                    'cancelled' => 'cancelled',
                ],
            ])
            ->add('law_type', ChoiceType::class, [
                'required' => false,
                'property_path' => 'lawType',
                'choices' => ['fz44' => 'fz44', 'fz223' => 'fz223', 'commercial' => 'commercial'],
            ])
            ->add('region', TextType::class, ['required' => false])
            ->add('okpd2', TextType::class, [
                'required' => false,
                'constraints' => [new Length(max: 20)],
            ])
            ->add('price_min', IntegerType::class, [
                'required' => false,
                'property_path' => 'priceMin',
            ])
            ->add('price_max', IntegerType::class, [
                'required' => false,
                'property_path' => 'priceMax',
            ])
            ->add('access_type', ChoiceType::class, [
                'required' => false,
                'property_path' => 'accessType',
                'choices' => ['open' => 'open', 'contract_holders' => 'contract_holders'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => TenderListFiltersInput::class,
        ]);
    }
}

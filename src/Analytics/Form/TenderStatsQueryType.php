<?php

declare(strict_types=1);

namespace App\Analytics\Form;

use App\Analytics\Input\TenderStatsQueryInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Query-параметры статистики по тендерам (GET /stats/tenders).
 *
 * dimension — срез (region/okpd2/customer/period, ChoiceType → невалидное 422);
 * from/to — границы периода (строки Y-m-d; формат/диапазон валидирует
 * TenderStatsService). data_class — App\Analytics\Input\TenderStatsQueryInput.
 *
 * @extends AbstractType<TenderStatsQueryInput>
 */
final class TenderStatsQueryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('dimension', ChoiceType::class, [
                'required' => false,
                'choices' => [
                    'region' => 'region',
                    'okpd2' => 'okpd2',
                    'customer' => 'customer',
                    'period' => 'period',
                ],
            ])
            ->add('from', TextType::class, ['required' => false])
            ->add('to', TextType::class, ['required' => false])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => TenderStatsQueryInput::class,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Analytics\Form;

use App\Analytics\Input\DashboardQueryInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Query-параметры дашборда (GET /dashboard, ?period=day|week|month).
 *
 * Необязательный период трендов; валидируется ChoiceType (невалидное → 422).
 * data_class — App\Analytics\Input\DashboardQueryInput.
 *
 * @extends AbstractType<DashboardQueryInput>
 */
final class DashboardQueryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('period', ChoiceType::class, [
                'required' => false,
                'choices' => ['day' => 'day', 'week' => 'week', 'month' => 'month'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => DashboardQueryInput::class,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Iam\Form;

use App\Iam\Entity\Enum\CompanyStatusEnum;
use App\Iam\Input\CompanyListFiltersInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Фильтры реестра компаний (GET /admin/companies, query-параметры).
 *
 * q — подстрока по названию/ИНН (ILIKE), status — статус верификации
 * (ChoiceType по CompanyStatusEnum::getValues(); невалидное → 422).
 * Пустое поле = фильтр не задан. data_class — CompanyListFiltersInput.
 *
 * @extends AbstractType<CompanyListFiltersInput>
 */
final class CompanyListFiltersType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('q', TextType::class, [
                'required' => false,
                'constraints' => [new Length(max: 255)],
            ])
            ->add('status', ChoiceType::class, [
                'required' => false,
                'choices' => CompanyStatusEnum::getValues(),
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => CompanyListFiltersInput::class,
        ]);
    }
}

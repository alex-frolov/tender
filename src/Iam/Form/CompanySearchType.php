<?php

declare(strict_types=1);

namespace App\Iam\Form;

use App\Iam\Input\CompanySearchInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Форма поиска компании (GET /companies/search). Пустой или слишком короткий
 * запрос → 422: подсказка контрагента начинается с осмысленной подстроки,
 * а не с перечисления всей площадки.
 *
 * @extends AbstractType<CompanySearchInput>
 */
final class CompanySearchType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('q', TextType::class, [
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(message: 'q is required'),
                    new Length(min: 2, max: 200),
                ],
            ])
            ->add('limit', IntegerType::class, [
                'required' => false,
                'constraints' => [new Range(min: 1, max: 50)],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => CompanySearchInput::class,
        ]);
    }
}

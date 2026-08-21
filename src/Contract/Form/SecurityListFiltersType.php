<?php

declare(strict_types=1);

namespace App\Contract\Form;

use App\Contract\Entity\Enum\SecurityKindEnum;
use App\Contract\Entity\Enum\SecurityStatusEnum;
use App\Contract\Input\SecurityListFiltersInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Фильтры списка обеспечения (GET /securities): вид (заявка/контракт) и статус —
 * оба необязательны, значения валидируются по enum (иначе 422).
 *
 * @extends AbstractType<SecurityListFiltersInput>
 */
final class SecurityListFiltersType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('kind', ChoiceType::class, [
                'required' => false,
                'choices' => SecurityKindEnum::getValues(),
            ])
            ->add('status', ChoiceType::class, [
                'required' => false,
                'choices' => SecurityStatusEnum::getValues(),
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => SecurityListFiltersInput::class,
        ]);
    }
}

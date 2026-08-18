<?php

declare(strict_types=1);

namespace App\Contract\Form;

use App\Contract\Input\ContractStageCreateInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Форма создания этапа исполнения (FR-1.4.3, POST /contract_tenders/{id}/stages).
 * title обязателен; number опционален (следующий по порядку при отсутствии);
 * amount_minor ≥ 0. Валидация JSON-тела — здесь, а не в контроллере.
 *
 * @extends AbstractType<ContractStageCreateInput>
 */
final class ContractStageCreateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('number', IntegerType::class, [
                'required' => false,
                'constraints' => [new Range(min: 1)],
            ])
            ->add('title', TextType::class, [
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(),
                    new Length(max: 300),
                ],
            ])
            ->add('amount_minor', IntegerType::class, [
                'property_path' => 'amountMinor',
                'required' => false,
                'constraints' => [new Range(min: 0)],
            ])
            ->add('due_at', TextType::class, [
                'property_path' => 'dueAt',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => ContractStageCreateInput::class,
        ]);
    }
}

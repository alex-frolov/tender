<?php

declare(strict_types=1);

namespace App\Question\Form;

use App\Question\Input\CreateQuestionInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Форма создания вопроса по тендеру (FR-1.2.9, POST /tenders/{tenderId}/questions).
 * text обязателен (1..4000); lot_id — опционален (вопрос по тендеру в целом).
 * Валидация JSON-тела — здесь, а не в контроллере.
 *
 * @extends AbstractType<CreateQuestionInput>
 */
final class CreateQuestionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('lot_id', TextType::class, [
                'property_path' => 'lotId',
                'required' => false,
            ])
            ->add('text', TextType::class, [
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(message: 'text is required'),
                    new Length(min: 1, max: 4000),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => CreateQuestionInput::class,
        ]);
    }
}

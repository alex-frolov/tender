<?php

declare(strict_types=1);

namespace App\Question\Form;

use App\Question\Input\AnswerQuestionInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Форма ответа на вопрос (FR-1.2.9,
 * POST /tenders/{tenderId}/questions/{questionId}/answer).
 * answer обязателен (1..4000); пустой ответ смысла не имеет — вопрос считается
 * разъяснённым только с текстом.
 *
 * @extends AbstractType<AnswerQuestionInput>
 */
final class AnswerQuestionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('answer', TextType::class, [
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(message: 'answer is required'),
                    new Length(min: 1, max: 4000),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => AnswerQuestionInput::class,
        ]);
    }
}

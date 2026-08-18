<?php

declare(strict_types=1);

namespace App\Complaint\Form;

use App\Complaint\Input\CreateComplaintInput;
use App\Shared\Form\JsonListType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Форма подачи жалобы по тендеру (FR-1.2.10, POST /tenders/{tenderId}/complaints).
 * text и ground обязательны (≥1); lot_id опционален; document_ids — список
 * uuid (JsonListType). Валидация JSON-тела — здесь, а не в контроллере.
 *
 * @extends AbstractType<CreateComplaintInput>
 */
final class CreateComplaintType extends AbstractType
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
                'constraints' => [new NotBlank(message: 'text is required')],
            ])
            ->add('ground', TextType::class, [
                'empty_data' => '',
                'constraints' => [new NotBlank(message: 'ground is required')],
            ])
            ->add('document_ids', JsonListType::class, [
                'property_path' => 'documentIds',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => CreateComplaintInput::class,
        ]);
    }
}

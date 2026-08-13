<?php

declare(strict_types=1);

namespace App\Document\Form;

use App\Document\Entity\Enum\DocumentEntityType;
use App\Document\Entity\Enum\DocumentScope;
use App\Document\Entity\Enum\DocumentVisibility;
use App\Document\Input\UploadDocumentInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\Uuid;

/**
 * Форма загрузки документа (AM-8, POST /documents, multipart/form-data).
 * file — обязательный бинарный файл; document_type_id/entity_type/entity_id —
 * обязательны; visibility/scope — необязательны (по умолчанию из document_type).
 *
 * @extends AbstractType<UploadDocumentInput>
 */
final class UploadDocumentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', FileType::class, [
                'constraints' => [new NotBlank(message: 'file is required')],
            ])
            ->add('document_type_id', IntegerType::class, [
                'property_path' => 'documentTypeId',
                'empty_data' => 0,
                'constraints' => [
                    new NotBlank(message: 'document_type_id is required'),
                    new Positive(message: 'document_type_id must be a positive integer'),
                ],
            ])
            ->add('entity_type', ChoiceType::class, [
                'property_path' => 'entityType',
                'empty_data' => '',
                'choices' => DocumentEntityType::getValues(),
                'constraints' => [new NotBlank(message: 'entity_type is required')],
            ])
            ->add('entity_id', TextType::class, [
                'property_path' => 'entityId',
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(message: 'entity_id is required'),
                    new Uuid(message: 'entity_id must be a valid UUID'),
                ],
            ])
            ->add('visibility', ChoiceType::class, [
                'choices' => DocumentVisibility::getValues(),
                'required' => false,
            ])
            ->add('scope', ChoiceType::class, [
                'choices' => DocumentScope::getValues(),
                'required' => false,
            ])
        ;
        $builder->get('entity_type')->addModelTransformer(new CallbackTransformer(
            static fn (?string $value): string => $value ?? '',
            static fn (?string $value): string => $value ?? '',
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => UploadDocumentInput::class,
        ]);
    }
}

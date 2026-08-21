<?php

declare(strict_types=1);

namespace App\Bid\Form;

use App\Bid\Input\AttachBidDocumentsInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Uuid as UuidConstraint;

/**
 * Форма привязки документов к части 2 заявки (POST /bids/{bidId}/documents).
 * Каждый элемент — uuid документа (невалидный → 422); отсутствие поля означает
 * пустой список, то есть очистку части 2.
 *
 * @extends AbstractType<AttachBidDocumentsInput>
 */
final class AttachBidDocumentsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('document_ids', CollectionType::class, [
                'property_path' => 'documentIds',
                'required' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_type' => TextType::class,
                'constraints' => [new All([new UuidConstraint()])],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => AttachBidDocumentsInput::class,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Document\Form;

use App\Document\Entity\DocumentType;
use App\Document\Entity\Enum\DocumentVisibility;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Форма изменения типа документа (FR-1.2.7, PUT /document-types/{id}, суперадмин).
 *
 * Форма привязана к сущности DocumentType (entity-bound update form, AGENTS.md):
 * контроллер резолвит тип через DocumentTypeRepository::findOrFail и передаёт его
 * как data ($form->getData() — та же сущность). Все поля необязательны за счёт
 * clearMissing=false в formInput: отсутствующие в теле поля сохраняют текущие
 * значения; active=false — деактивация.
 *
 * Все свойства сущности здесь NOT NULL, поэтому у текстового и enum-полей
 * empty_data-замыкания возвращают текущее значение: пустая строка = «оставить»,
 * а не «записать null» (иначе сеттер получил бы null и упал на типизации).
 *
 * @extends AbstractType<DocumentType>
 */
final class UpdateDocumentTypeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'required' => false,
                'empty_data' => static fn (FormInterface $form): string => self::current($form)?->getName() ?? '',
                'constraints' => [new Length(max: 200)],
            ])
            ->add('owner_role', ChoiceType::class, [
                'property_path' => 'ownerRole',
                'choices' => ['customer' => 'customer', 'executor' => 'executor', 'both' => 'both'],
                'required' => false,
                'empty_data' => static fn (FormInterface $form): string => self::current($form)?->getOwnerRole() ?? '',
            ])
            ->add('visibility', ChoiceType::class, [
                'choices' => DocumentVisibility::getValues(),
                'required' => false,
                'empty_data' => static fn (FormInterface $form): string => self::current($form)?->getVisibility() ?? '',
            ])
            ->add('required', CheckboxType::class, [
                'required' => false,
            ])
            ->add('active', CheckboxType::class, [
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => DocumentType::class,
        ]);
    }

    /**
     * Резолвленная сущность формы (data родительской формы) — источник текущих
     * значений для empty_data.
     *
     * @param FormInterface<object> $form
     */
    private static function current(FormInterface $form): ?DocumentType
    {
        $type = $form->getParent()?->getData();

        return $type instanceof DocumentType ? $type : null;
    }
}

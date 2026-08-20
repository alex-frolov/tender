<?php

declare(strict_types=1);

namespace App\Iam\Form;

use App\Iam\Entity\Company;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Форма изменения реквизитов компании (FR-1.5.4, PATCH /companies).
 *
 * Форма привязана к сущности Company (entity-bound update form, AGENTS.md):
 * контроллер резолвит компанию и передаёт её как data ($form->getData() —
 * та же сущность). PATCH-семантика за счёт clearMissing=false в formInput:
 * поля, отсутствующие в теле запроса, сохраняют текущие значения; пустая
 * строка/null очищает значение (кроме legal_name — реквизит обязателен).
 * Имена полей — snake_case (как в openapi CompanyUpdate).
 *
 * @extends AbstractType<Company>
 */
final class CompanyUpdateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('legal_name', TextType::class, [
                'property_path' => 'legalName',
                'required' => false,
                // пустая строка/null — оставить текущее значение (реквизит обязателен)
                'empty_data' => static fn (FormInterface $form): string => self::currentLegalName($form),
                'constraints' => [new Length(max: 300)],
            ])
            ->add('kpp', TextType::class, [
                'required' => false,
                'empty_data' => null,
                'constraints' => [new Length(max: 12)],
            ])
            ->add('ogrn', TextType::class, [
                'required' => false,
                'empty_data' => null,
                'constraints' => [new Length(max: 20)],
            ])
            ->add('address', TextType::class, [
                'required' => false,
                'empty_data' => null,
                'constraints' => [new Length(max: 500)],
            ])
            ->add('contacts', CollectionType::class, [
                'required' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_type' => TextType::class,
            ])
        ;

        // Пустой массив контактов = очистить (null), как в openapi CompanyUpdate.
        $builder->get('contacts')->addModelTransformer(new CallbackTransformer(
            transform: static fn (mixed $contacts): mixed => $contacts,
            reverseTransform: static fn (mixed $contacts): mixed => [] === $contacts ? null : $contacts,
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => Company::class,
        ]);
    }

    /**
     * @param FormInterface<object> $form
     */
    private static function currentLegalName(FormInterface $form): string
    {
        $company = $form->getParent()?->getData();

        return $company instanceof Company ? $company->getLegalName() : '';
    }
}

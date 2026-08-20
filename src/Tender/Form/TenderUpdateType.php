<?php

declare(strict_types=1);

namespace App\Tender\Form;

use App\Tender\Input\UpdateTenderInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Форма изменения тендера (FR-1.1.1, PATCH /tenders/{tenderId}).
 * Правка допустимых полей до окончания приёма заявок; все поля опциональны
 * (null = не менять). change_reason — причина правки (для аудита).
 * Имена полей — snake_case (как в openapi TenderUpdate).
 *
 * @extends AbstractType<UpdateTenderInput>
 */
final class TenderUpdateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'required' => false,
                'constraints' => [new Length(max: 500)],
            ])
            // empty_data: '' обязателен для очищаемых полей. По умолчанию форма
            // приводит присланную пустую строку к null, а null в UpdateTenderInput
            // означает «ключа не было, не менять» — из-за этого очистка через
            // TenderService (там '' → null в сущности) была недостижима.
            // Отсутствующий ключ сюда не попадает: formInput сабмитит с
            // clearMissing: false, то есть несабмиченное поле остаётся null.
            ->add('description', TextType::class, [
                'required' => false,
                'empty_data' => '',
                'constraints' => [new Length(max: 10000)],
            ])
            ->add('region', TextType::class, [
                'required' => false,
                'empty_data' => '',
                'constraints' => [new Length(max: 100)],
            ])
            ->add('okpd2', TextType::class, [
                'required' => false,
                'empty_data' => '',
                'constraints' => [new Length(max: 20)],
            ])
            ->add('timeline', CollectionType::class, [
                'required' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'entry_type' => TextType::class,
            ])
            ->add('change_reason', TextType::class, [
                'property_path' => 'changeReason',
                'required' => false,
                'constraints' => [new Length(max: 1000)],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => UpdateTenderInput::class,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Export\Form;

use App\Export\Entity\Enum\ExportFormatEnum;
use App\Export\Entity\Enum\ExportTypeEnum;
use App\Export\Input\CreateExportInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Форма запроса экспорта (UC-31, POST /exports, openapi ExportCreate).
 * Обязательные: export_type (tenders/bids/contracts), format (xlsx/csv).
 * filters — произвольный JSON-объект (ExportFiltersType). Имена полей —
 * snake_case (openapi export_type/format/filters).
 *
 * @extends AbstractType<CreateExportInput>
 */
final class CreateExportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('export_type', ChoiceType::class, [
                'property_path' => 'exportType',
                'required' => true,
                'choices' => ExportTypeEnum::getValues(),
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            ->add('format', ChoiceType::class, [
                'property_path' => 'format',
                'required' => true,
                'choices' => ExportFormatEnum::getValues(),
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            ->add('filters', ExportFiltersType::class, [
                'property_path' => 'filters',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => CreateExportInput::class,
        ]);
    }
}

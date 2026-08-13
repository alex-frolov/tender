<?php

declare(strict_types=1);

namespace App\Export\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Поле формы для JSON-объекта фильтров экспорта (openapi POST /exports
 * filters, additionalProperties: true, UC-31).
 *
 * Принимает произвольный JSON-объект из тела запроса и хранит его как массив
 * (например {"status": "accepting_bids", "from": "2026-01-01"}). Аналог
 * WebhookFiltersType (Platform), но в модуле Export — по границам модулей
 * (PHPArkitect rule 6) чужие Form-классы недоступны.
 *
 * @extends AbstractType<array<string, mixed>|null>
 */
final class ExportFiltersType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            static function (mixed $value): string {
                if (!\is_array($value)) {
                    return '';
                }

                return (string) json_encode($value, \JSON_UNESCAPED_UNICODE);
            },
            static function (mixed $value): array {
                if (\is_array($value)) {
                    return $value;
                }

                if (!\is_string($value) || '' === trim($value)) {
                    return [];
                }

                $decoded = json_decode($value, true);

                return \is_array($decoded) ? $decoded : [];
            },
        ));
    }

    public function getParent(): string
    {
        return \Symfony\Component\Form\Extension\Core\Type\TextType::class;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'multiple' => true,
            'compound' => false,
            'data_class' => null,
        ]);
    }
}

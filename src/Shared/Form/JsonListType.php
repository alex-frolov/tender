<?php

declare(strict_types=1);

namespace App\Shared\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Поле формы для свободного JSON-массива (openapi ProcurementPlanCreate.items,
 * CreateComplaint.document_ids). Принимает список объектов из тела запроса и
 * хранит его как массив. `multiple` позволяет Symfony принимать массив как
 * значение поля (вместо TransformationFailedException «array given»).
 *
 * @extends AbstractType<list<array<string, mixed>>|null>
 */
final class JsonListType extends AbstractType
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
                    return array_values($value);
                }

                if (!\is_string($value) || '' === trim($value)) {
                    return [];
                }

                $decoded = json_decode($value, true);

                return \is_array($decoded) ? array_values($decoded) : [];
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

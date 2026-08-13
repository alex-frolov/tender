<?php

declare(strict_types=1);

namespace App\Shared\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Поле формы для JSON-объекта фильтров webhook-подписки (openapi
 * WebhookCreate.filters, additionalProperties: true, WH-7).
 *
 * Принимает произвольный JSON-объект из тела запроса и хранит его как массив
 * (например {"tender_id": "<uuid>"}). `multiple` позволяет Symfony принимать
 * массив как значение поля (вместо TransformationFailedException «array given»).
 *
 * @extends AbstractType<array<string, mixed>|null>
 */
final class WebhookFiltersType extends AbstractType
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

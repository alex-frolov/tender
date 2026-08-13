<?php

declare(strict_types=1);

namespace App\Platform\Form;

use App\Platform\Input\CreateApiKeyInput;
use App\Platform\Service\ApiKeyScopes;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Форма выпуска API-ключа (FR-1.5.13, POST /api-keys).
 * Имена полей — snake_case (openapi ApiKeyCreate). Обязательное: name.
 * scopes — только из каталога ApiKeyScopes (Choice + Collection); expires_at —
 * ISO-8601 datetime (nullable). Валидация формата/прошлого срока — в ApiKeyService
 * (expires_at здесь — TextType без формат-валидации, чтобы отдать raw-значение).
 *
 * @extends AbstractType<CreateApiKeyInput>
 */
final class ApiKeyCreateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'property_path' => 'name',
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(),
                    new Length(min: 1, max: 100),
                ],
            ])
            ->add('scopes', CollectionType::class, [
                'property_path' => 'scopes',
                'required' => false,
                'allow_add' => true,
                'entry_type' => TextType::class,
                'constraints' => [
                    new All([
                        new NotBlank(),
                        new Choice(
                            choices: ApiKeyScopes::catalog(),
                            message: 'scope must be from the ApiKeyScopes catalog',
                        ),
                    ]),
                ],
            ])
            ->add('expires_at', TextType::class, [
                'property_path' => 'expiresAt',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => CreateApiKeyInput::class,
        ]);
    }
}

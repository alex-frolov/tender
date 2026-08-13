<?php

declare(strict_types=1);

namespace App\Iam\Form;

use App\Iam\Entity\Enum\CompanyTypeEnum;
use App\Iam\Entity\Enum\LocaleEnum;
use App\Iam\Input\RegisterInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Форма регистрации компании (FR-1.5.4): обязательные поля компании и админа,
 * org_type/locale — ChoiceType через enum-методы (label == value). Валидация
 * входных данных JSON-тела POST /auth/register — здесь, а не в контроллере.
 *
 * @extends AbstractType<RegisterInput>
 */
final class RegisterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('company_name', TextType::class, [
                'property_path' => 'companyName',
                'empty_data' => '',
                'constraints' => [new NotBlank()],
            ])
            ->add('inn', TextType::class, [
                'empty_data' => '',
                'constraints' => [new NotBlank()],
            ])
            ->add('org_type', ChoiceType::class, [
                'property_path' => 'orgType',
                'empty_data' => '',
                'choices' => CompanyTypeEnum::getValues(),
                'constraints' => [new NotBlank()],
            ])
            ->add('email', TextType::class, [
                'empty_data' => '',
                'constraints' => [new NotBlank(), new Email()],
            ])
            ->add('password', TextType::class, [
                'empty_data' => '',
                'constraints' => [new NotBlank()],
            ])
            ->add('user_name', TextType::class, [
                'property_path' => 'userName',
                'empty_data' => '',
                'constraints' => [new NotBlank()],
            ])
            ->add('locale', ChoiceType::class, [
                'choices' => LocaleEnum::getValues(),
                'required' => false,
            ])
        ;
        $builder->get('org_type')->addModelTransformer(new CallbackTransformer(
            static fn (?string $value): string => $value ?? '',
            static fn (?string $value): string => $value ?? '',
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => RegisterInput::class,
        ]);
    }
}

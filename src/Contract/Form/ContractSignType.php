<?php

declare(strict_types=1);

namespace App\Contract\Form;

use App\Contract\Input\SignContractInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Форма подписания договора (FR-1.4.3, POST /contracts/{id}/sign).
 * party — customer|supplier (обязателен, openapi); signature — ЭП/УКЭП-заглушка
 * (в MVP произвольная строка, необязательная). Дубликат-подпись стороны и
 * неверный статус — бизнес-правила в ContractService (409).
 *
 * @extends AbstractType<SignContractInput>
 */
final class ContractSignType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('party', ChoiceType::class, [
                'empty_data' => '',
                'constraints' => [new NotBlank()],
                'choices' => ['customer' => 'customer', 'supplier' => 'supplier'],
            ])
            ->add('signature', TextType::class, [
                'required' => false,
            ])
        ;
        $builder->get('party')->addModelTransformer(new CallbackTransformer(
            static fn (?string $value): string => $value ?? '',
            static fn (?string $value): string => $value ?? '',
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => SignContractInput::class,
        ]);
    }
}

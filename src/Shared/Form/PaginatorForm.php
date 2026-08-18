<?php

declare(strict_types=1);

namespace App\Shared\Form;

use App\Shared\Input\Paginator;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Пагинация списков API (GET-query: ?limit=&cursor=).
 *
 * Единая форма для всех списков с keyset-курсором (Tender, Contract и т.д.):
 * limit 1..100 (default 20, значения вне диапазона клампятся через
 * Paginator::limitValue), cursor — OPAQUE-строка из предыдущего ответа.
 * data_class — App\Shared\Input\Paginator.
 *
 * @extends AbstractType<Paginator>
 */
final class PaginatorForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('limit', IntegerType::class, [
                'required' => false,
            ])
            ->add('cursor', TextType::class, [
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => Paginator::class,
        ]);
    }
}

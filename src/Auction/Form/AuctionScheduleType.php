<?php

declare(strict_types=1);

namespace App\Auction\Form;

use App\Auction\Input\ScheduleAuctionInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Форма планирования аукциона (T10, POST /auctions/{id}/schedule).
 * scheduled_start_at — дата/время старта (ISO-8601); валидация «в будущем»
 * и разбор — в AuctionWriteService.
 *
 * @extends AbstractType<ScheduleAuctionInput>
 */
final class AuctionScheduleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('scheduled_start_at', TextType::class, [
                'property_path' => 'scheduledStartAt',
                'empty_data' => '',
                'constraints' => [new NotBlank(message: 'scheduled_start_at is required')],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => ScheduleAuctionInput::class,
        ]);
    }
}

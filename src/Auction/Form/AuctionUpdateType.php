<?php

declare(strict_types=1);

namespace App\Auction\Form;

use App\Auction\Entity\Enum\AuctionStepModeEnum;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\Auction\Input\UpdateAuctionInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Форма правки аукциона до торгов (PATCH /auctions/{id}, FR-1.3.1).
 *
 * Все поля необязательны (PATCH-семантика: меняется только переданное).
 * Поля, не входящие в форму (канонические из лота: price_basis/vat_rate_bps/
 * trade_end_lead_hours; scheduled_start_at), отклоняются формой (422
 * «extra fields») — их меняют через лот/тендер или schedule.
 *
 * @extends AbstractType<UpdateAuctionInput>
 */
final class AuctionUpdateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'required' => false,
                'choices' => AuctionTypeEnum::getValues(),
            ])
            ->add('step_mode', ChoiceType::class, [
                'property_path' => 'stepMode',
                'required' => false,
                'choices' => AuctionStepModeEnum::getValues(),
            ])
            ->add('bid_step_minor', IntegerType::class, [
                'property_path' => 'bidStepMinor',
                'required' => false,
                'constraints' => [new Range(min: 0, minMessage: 'bid_step_minor must be >= 0 (minor units, PR-1)')],
            ])
            ->add('bid_step_percent_bps', IntegerType::class, [
                'property_path' => 'bidStepPercentBps',
                'required' => false,
                'constraints' => [new Range(min: 0, minMessage: 'bid_step_percent_bps must be >= 0 (bps, PR-4)')],
            ])
            ->add('price_min_limit_minor', IntegerType::class, [
                'property_path' => 'priceMinLimitMinor',
                'required' => false,
                'constraints' => [new Range(min: 0, minMessage: 'price_min_limit_minor must be >= 0 (minor units, PR-1)')],
            ])
            ->add('price_max_limit_minor', IntegerType::class, [
                'property_path' => 'priceMaxLimitMinor',
                'required' => false,
                'constraints' => [new Range(min: 0, minMessage: 'price_max_limit_minor must be >= 0 (minor units, PR-1)')],
            ])
            ->add('step_duration_sec', IntegerType::class, [
                'property_path' => 'stepDurationSec',
                'required' => false,
                'constraints' => [new Range(min: 1, minMessage: 'step_duration_sec must be >= 1')],
            ])
            ->add('max_extensions', IntegerType::class, [
                'property_path' => 'maxExtensions',
                'required' => false,
                'constraints' => [new Range(min: 0, minMessage: 'max_extensions must be >= 0')],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => UpdateAuctionInput::class,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Auction\Form;

use App\Auction\Entity\Enum\AuctionStepModeEnum;
use App\Auction\Entity\Enum\AuctionTypeEnum;
use App\Auction\Input\CreateAuctionInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Форма создания аукциона (FR-1.3, POST /auctions).
 *
 * Обязательные поля: lot_id, type. step_mode по умолчанию fixed; шаг задаётся
 * либо bid_step_minor, либо bid_step_percent_bps (REDUCTION+fixed, PR-4);
 * лимиты цен — price_min/max_limit_minor (minor units, PR-1). Канонические
 * параметры (база/НДС/стартовая цена) наследуются от лота в сервисе.
 * Имена полей — snake_case (как в openapi AuctionCreate).
 *
 * @extends AbstractType<CreateAuctionInput>
 */
final class AuctionCreateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('lot_id', TextType::class, [
                'property_path' => 'lotId',
                'empty_data' => '',
                'constraints' => [new NotBlank(message: 'lot_id is required')],
            ])
            ->add('type', ChoiceType::class, [
                'empty_data' => '',
                'constraints' => [new NotBlank(message: 'type is required')],
                'choices' => AuctionTypeEnum::getValues(),
            ])
            ->add('step_mode', ChoiceType::class, [
                'property_path' => 'stepMode',
                'required' => false,
                'placeholder' => AuctionStepModeEnum::FIXED->value,
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
            ->add('scheduled_start_at', TextType::class, [
                'property_path' => 'scheduledStartAt',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'data_class' => CreateAuctionInput::class,
        ]);
    }
}

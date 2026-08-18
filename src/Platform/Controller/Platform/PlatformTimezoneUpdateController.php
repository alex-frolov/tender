<?php

declare(strict_types=1);

namespace App\Platform\Controller\Platform;

use App\Controller\AbstractBaseController;
use App\Platform\Form\PlatformTimezoneUpdateType;
use App\Platform\Input\PlatformTimezoneUpdateInput;
use App\Platform\UseCase\UpdatePlatformTimezoneUseCase;
use App\Security\PlatformVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Установка доменного часового пояса платформы (FR-1.5.16, PUT /platform/timezone).
 * Только суперадмин с правом platform.timezone.manage (PlatformVoter).
 * Валидацию тела выполняет PlatformTimezoneUpdateType (422), IANA-проверку —
 * PlatformSettingsService. Контракт: api/openapi.yaml (/platform/timezone PUT).
 */
final class PlatformTimezoneUpdateController extends AbstractBaseController
{
    public const string URL = '/api/v1/platform/timezone';

    #[Route(self::URL, name: 'platform_timezone_update', methods: [Request::METHOD_PUT])]
    #[IsGranted(PlatformVoter::TIMEZONE_MANAGE)]
    public function update(Request $request, UpdatePlatformTimezoneUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $form = $this->formInput(PlatformTimezoneUpdateType::class, $request);
        /** @var PlatformTimezoneUpdateInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute($user, $input, $request->getClientIp()));
    }
}

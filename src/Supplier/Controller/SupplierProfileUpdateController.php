<?php

declare(strict_types=1);

namespace App\Supplier\Controller;

use App\Controller\AbstractBaseController;
use App\Security\SupplierVoter;
use App\Supplier\Form\SupplierProfileUpdateType;
use App\Supplier\Input\SupplierProfileUpdateInput;
use App\Supplier\UseCase\UpdateSupplierProfileUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Обновление профиля поставщика (FR-1.5.5, PUT /suppliers/profile).
 * Только admin компании (SupplierVoter::UPDATE_PROFILE; не platform_admin).
 * Валидацию тела выполняет SupplierProfileUpdateType (422 при невалидных).
 * Контракт: api/openapi.yaml (/suppliers/profile PUT).
 */
final class SupplierProfileUpdateController extends AbstractBaseController
{
    public const string URL = '/api/v1/suppliers/profile';

    #[Route(self::URL, name: 'supplier_profile_update', methods: [Request::METHOD_PUT])]
    #[IsGranted(SupplierVoter::UPDATE_PROFILE)]
    public function update(Request $request, UpdateSupplierProfileUseCase $useCase): JsonResponse
    {
        $user = $this->currentUser($request);

        $form = $this->formInput(SupplierProfileUpdateType::class, $request);
        /** @var SupplierProfileUpdateInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute($user, $input, $request->getClientIp()));
    }
}

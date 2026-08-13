<?php

declare(strict_types=1);

namespace App\Iam\Controller\Auth;

use App\Controller\AbstractBaseController;
use App\Iam\Form\RegisterType;
use App\Iam\Input\RegisterInput;
use App\Iam\UseCase\RegisterUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Регистрация компании (FR-1.5.6).
 * Валидацию входных данных выполняет форма RegisterType (422 при невалидных,
 * включая невалидный JSON-тело), оркестрацию и презентацию — RegisterUseCase
 * (прикладной слой модуля); ошибки (ConflictException при повторном ИНН) в JSON
 * превращает JsonApiExceptionSubscriber.
 * Контракт: api/openapi.yaml (/auth/register).
 */
final class RegisterController extends AbstractBaseController
{
    public const string URL = '/api/v1/auth/register';

    #[Route(self::URL, name: 'auth_register', methods: [Request::METHOD_POST])]
    public function register(Request $request, RegisterUseCase $useCase): JsonResponse
    {
        $form = $this->formInput(RegisterType::class, $request, strict: true);
        /** @var RegisterInput $input */
        $input = $form->getData();

        return $this->json($useCase->execute($input), Response::HTTP_CREATED);
    }
}

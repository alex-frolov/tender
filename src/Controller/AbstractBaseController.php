<?php

declare(strict_types=1);

namespace App\Controller;

use App\Iam\Entity\User;
use App\Iam\Service\AuthMiddleware;
use App\Shared\Exception\UnauthorizedException;
use App\Shared\Exception\ValidationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Общая логика API-контроллеров: парсинг JSON-тела, чтение строковых полей,
 * текущий пользователь из AuthMiddleware (UnauthorizedException при отсутствии).
 * Правило: один контроллер — один route (см. AGENTS.md).
 */
abstract class AbstractBaseController extends AbstractController
{
    public function __construct(private readonly FormFactoryInterface $formFactory)
    {
    }

    protected function currentUser(Request $request): User
    {
        $user = $request->attributes->get(AuthMiddleware::ATTR_USER);

        if (!$user instanceof User) {
            throw new UnauthorizedException();
        }

        return $user;
    }

    /**
     * @return array<mixed>|null
     */
    protected function jsonBody(Request $request): ?array
    {
        $data = json_decode((string) $request->getContent(), true);

        return \is_array($data) ? $data : null;
    }

    /**
     * Создать и засабмитить форму из JSON-тела запроса (validation-by-form).
     * При невалидных данных бросает ValidationException (422) — подписчик
     * JsonApiExceptionSubscriber превращает её в JSON-ответ. data_class формы
     * доступна через $form->getData().
     *
     * $strict=true (формы с полностью nullable DTO): непустое тело с некорректным
     * JSON — 422; отсутствующие поля очищаются в null и проходят валидацию
     * (обязательные NotBlank → 422). По умолчанию false — поведение как было:
     * пустое/невалидное тело трактуется как «нет данных», отсутствующие поля
     * не очищаются (важно для форм с не-nullable полями и дефолтами).
     *
     * $data — предзаполненный объект (data_class) для форм, которым нужно
     * различать «поле не передано» и «явный null» (PATCH со сбросом): дефолты
     * DTO сохраняются для отсутствующих полей (clearMissing=false).
     *
     * @return FormInterface<null>
     */
    protected function formInput(string $type, Request $request, bool $strict = false, ?object $data = null): FormInterface
    {
        $dataBody = $this->jsonBody($request);
        if ($strict && null === $dataBody && '' !== trim((string) $request->getContent())) {
            throw new ValidationException('Invalid JSON body');
        }

        /** @var class-string<FormTypeInterface<object>> $formType */
        $formType = $type;
        /** @var FormInterface<null> $form */
        $form = $this->createForm($formType, $data, ['csrf_protection' => false]);
        $form->submit($dataBody ?? [], $strict);

        if (!$form->isValid()) {
            throw new ValidationException($this->formErrorsMessage($form));
        }

        return $form;
    }

    /**
     * Создать и обработать multipart-форму (файловые загрузки, AM-8).
     * Использует handleRequest — файлы из $request->files и поля из $request->request.
     * При невалидных данных бросает ValidationException (422).
     *
     * @return FormInterface<null>
     */
    protected function multipartFormInput(string $type, Request $request): FormInterface
    {
        /** @var class-string<FormTypeInterface<null>> $formType */
        $formType = $type;
        $form = $this->formFactory->createNamed('', $formType, null, ['csrf_protection' => false]);
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            throw new ValidationException('form is not submitted');
        }
        if (!$form->isValid()) {
            throw new ValidationException($this->formErrorsMessage($form));
        }

        return $form;
    }

    /**
     * @param FormInterface<null> $form
     */
    protected function formErrorsMessage(FormInterface $form): string
    {
        $messages = [];
        foreach ($form->getErrors(true) as $error) {
            $messages[] = $error->getMessage();
        }

        return implode('; ', $messages);
    }

    /**
     * @param array<mixed> $data
     */
    protected function stringField(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? $default;
        if (!\is_string($value)) {
            return $default;
        }

        return $value;
    }
}

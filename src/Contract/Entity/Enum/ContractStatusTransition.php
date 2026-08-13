<?php

declare(strict_types=1);

namespace App\Contract\Entity\Enum;

/**
 * Переходы состояния договора (domain/contract-state-machine.md, FR-1.4.3).
 * Имена переходов для symfony/workflow (config/workflow/contract.yaml).
 *
 * C0–C2: инициация (draft) → отправка на подписание (pending_signature) →
 * signed (подписи обеих сторон, guard по флагам сторон). C3: возврат на
 * доработку. C4/C5/C11/C12: мягкое удаление (deleted). C6: регистрация.
 * C7/C9: расторжение (terminated). C8/C10: истечение срока (expired).
 */
enum ContractStatusTransition: string
{
    case SEND_FOR_SIGNATURE = 'send_for_signature';
    case SIGN = 'sign';
    case BACK_TO_DRAFT = 'back_to_draft';
    case REGISTER = 'register';
    case TERMINATE = 'terminate';
    case EXPIRE = 'expire';
    case DELETE = 'delete';
}

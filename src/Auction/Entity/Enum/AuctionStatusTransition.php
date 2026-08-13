<?php

declare(strict_types=1);

namespace App\Auction\Entity\Enum;

/**
 * Переходы состояния аукциона (domain/auction-state-machine.md, FR-1.3.7,
 * таблица переходов T1–T38). Имена переходов для symfony/workflow
 * (config/workflow/auction.yaml).
 *
 * Некоторые имена покрывают несколько логических переходов (multi-from,
 * единый to): cancel (T7/T9/T12/T14/T19/T22/T25/T28/T32/T38 → CANCELLED),
 * expire (T11/T18/T24 → EXPIRED), confirm_done (T27/T31/T34 → DONE),
 * claim (T29/T33/T35 → CLAIM) — в workflow-конфигурации задаются одним
 * transition с несколькими from.
 *
 * CREATED — фиктивный статус (не перситится); переходы T1–T3 (persist_*)
 * описывают пути создания аукциона: черновик (draft), сразу к торговле (new)
 * или на согласование (agreement). В workflow initial_marking = new.
 */
enum AuctionStatusTransition: string
{
    // ── Создание (CREATED — фиктивный, T1–T3) ──
    case PERSIST_TO_DRAFT = 'persist_to_draft';
    case PERSIST_TO_NEW = 'persist_to_new';
    case PERSIST_TO_AGREEMENT = 'persist_to_agreement';

    // ── DRAFT (T4–T7) ──
    case PUBLISH = 'publish';
    case REQUEST_AGREEMENT = 'request_agreement';
    case DELETE = 'delete';
    case CANCEL = 'cancel';

    // ── AGREEMENT (T8–T9) ──
    case APPROVE_AGREEMENT = 'approve_agreement';

    // ── NEW (T10–T12) ──
    case SCHEDULE = 'schedule';
    case EXPIRE = 'expire';

    // ── SCHEDULED (T13–T15) ──
    case START_TRADE = 'start_trade';
    case UNSCHEDULE = 'unschedule';

    // ── TRADE (T16–T20) ──
    case FINISH = 'finish';
    case CHOOSE_WINNER_MANUAL = 'choose_winner_manual';
    case PAUSE = 'pause';

    // ── PAUSED (T21–T22) ──
    case RESUME = 'resume';

    // ── CHOICE (T23–T25) ──
    case APPROVE_WINNER = 'approve_winner';

    // ── APPROVE / IN_WORK / DONE_BY_PERFORMER (T26–T35) ──
    case START_WORK = 'start_work';
    case CONFIRM_DONE = 'confirm_done';
    case CLAIM = 'claim';
    case MARK_DONE_BY_PERFORMER = 'mark_done_by_performer';

    // ── CLAIM (T36–T38) ──
    case RESOLVE_CLAIM = 'resolve_claim';
    case ACCEPT_CLAIM = 'accept_claim';
}

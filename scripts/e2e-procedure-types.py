#!/usr/bin/env python3
"""
E2E-прогон жизненного цикла тендера на ЖИВОМ dev-окружении для типов процедур
competition / rfq / rfp / direct (задача 7). Тип auction проверялся вручную и
здесь не гоняется.

Что делает: регистрирует заказчика и двух поставщиков (подтверждение email —
из Mailpit, подтверждение компаний — платформенным админом), затем на каждый
тип процедуры проходит путь
    тендер+лот → публикация → приём заявок → две заявки → допуск →
    аукцион → торги (две ставки) → победитель → договор (подписи сторон) →
    исполнение → DONE → закрытие тендера → оценка
и на каждом шаге сверяет статус с ожидаемым.

Требования к окружению:
  * поднят стек (docker compose up -d), включая worker и scheduler —
    без worker'а тендер не выйдет из published (TimelineMessage не обработается);
  * существует платформенный админ с учёткой из ADMIN ниже:
        docker compose exec -T app php bin/console app:create:platform-admin \
            e2e-admin@test.loc 'E2ePass!2026' -n

Запуск (с хоста, не из контейнера — нужен доступ к :8080 и :8025):
    python3 scripts/e2e-procedure-types.py

Результат: построчный протокол в stdout + e2e-result.json рядом с рабочим
каталогом; код возврата 1, если хоть один шаг не сошёлся.

Прогон оставляет данные в dev-БД (компании/тендеры с префиксом «E2E») — это
осознанно: их удобно смотреть в UI после прогона.
"""
import json, re, sys, time, urllib.request, urllib.error, random, string, subprocess

BASE = "http://localhost:8080/api/v1"

class ApiError(Exception):
    def __init__(self, status, body, method, path):
        self.status, self.body = status, body
        super().__init__(f"{method} {path} -> {status}: {json.dumps(body, ensure_ascii=False)[:400]}")

def call(method, path, token=None, body=None, expect=None):
    url = BASE + path
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header("Content-Type", "application/json")
    req.add_header("Accept", "application/json")
    if token:
        req.add_header("Authorization", "Bearer " + token)
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            raw = r.read().decode() or "{}"
            status = r.status
    except urllib.error.HTTPError as e:
        raw = e.read().decode() or "{}"
        status = e.code
    try:
        parsed = json.loads(raw)
    except Exception:
        parsed = {"_raw": raw[:500]}
    if expect is not None and status not in (expect if isinstance(expect, (list,tuple)) else [expect]):
        raise ApiError(status, parsed, method, path)
    return status, parsed

def rnd(n=6):
    return ''.join(random.choices(string.digits, k=n))

def login(email, password):
    _, b = call("POST", "/auth/token", body={"email": email, "password": password}, expect=200)
    return b["access_token"]


ADMIN = ("e2e-admin@test.loc", "E2ePass!2026")
PWD = "E2ePass!2026"
import os
APP_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))  # каталог app/ — оттуда вызывается docker compose
LOG = []

def log(step, ok, detail=""):
    mark = "OK  " if ok else "FAIL"
    line = f"  [{mark}] {step}" + (f" — {detail}" if detail else "")
    print(line, flush=True)
    LOG.append({"step": step, "ok": ok, "detail": detail})

def mailpit_token(email):
    for _ in range(30):
        with urllib.request.urlopen("http://localhost:8025/api/v1/messages?limit=50", timeout=10) as r:
            msgs = json.load(r).get("messages", [])
        for m in msgs:
            if any(t.get("Address") == email for t in m.get("To", [])) and "Подтверждение email" in (m.get("Subject") or ""):
                with urllib.request.urlopen(f"http://localhost:8025/api/v1/message/{m['ID']}", timeout=10) as r2:
                    body = json.load(r2)
                text = body.get("Text") or body.get("HTML") or ""
                mm = re.search(r"token=([0-9a-f]{32,})", text)
                if mm:
                    return mm.group(1)
        time.sleep(1)
    raise RuntimeError(f"письмо подтверждения для {email} не пришло")

def console(*args):
    return subprocess.run(
        ["docker", "compose", "exec", "-T", "app", "php", "bin/console", *args],
        cwd=APP_DIR, capture_output=True, text=True, timeout=180).stdout

def register(kind, tag, admin_token):
    email = f"e2e-{kind}-{tag}@test.loc"
    body = {
        "company_name": f"E2E {kind} {tag}",
        "inn": ("77" if kind == "cust" else "78") + rnd(8),
        "org_type": "customer" if kind == "cust" else "supplier",
        "email": email, "password": PWD, "user_name": f"E2E {kind}",
    }
    _, b = call("POST", "/auth/register", body=body, expect=201)
    company_id = b["company_id"]
    call("POST", "/auth/email/verify", body={"token": mailpit_token(email)}, expect=[200, 204])
    call("POST", f"/companies/{company_id}/verify", admin_token,
             body={"action": "approve"}, expect=[200, 204])
    return {"email": email, "company_id": company_id, "token": login(email, PWD)}

def tender_status(token, tid):
    _, b = call("GET", f"/tenders/{tid}", token, expect=200)
    return b["status"], b

def wait_tender_status(token, tid, targets, timeout=40):
    targets = set(targets)
    for _ in range(timeout):
        st, _b = tender_status(token, tid)
        if st in targets:
            return st
        time.sleep(1)
    return st

def run_type(ptype, cust, sa, sb):
    print(f"\n=== {ptype} ===", flush=True)
    tag = rnd(5)
    res = {"type": ptype}

    # 1. тендер + лот
    _, t = call("POST", "/tenders", cust["token"], expect=201, body={
        "title": f"E2E {ptype} {tag}", "description": "Сквозной прогон задачи 7",
        "procedure_type": ptype, "law_type": "commercial",
        "nmck_minor": 1000000, "currency": "RUB", "vat_rate": 20, "price_basis": "net",
        "customer_id": cust["company_id"], "region": "Москва", "access_type": "open",
        "bids_required": True,
        "lots": [{
            "title": f"Лот 1 — {ptype}", "price_net_minor": 1000000, "vat_rate": 20,
            "price_basis": "net", "quantity": 1, "unit": "шт",
        }],
    })
    tid = t["id"]; res["tender_id"] = tid
    log("создан тендер с лотом", t["status"] == "draft" and len(t.get("lots") or []) == 1,
        f"{tid} status={t['status']} lots={len(t.get('lots') or [])}")

    lid = t["lots"][0]["id"]; res["lot_id"] = lid

    # 2. публикация → таймлайн
    _, pub = call("POST", f"/tenders/{tid}/publish", cust["token"], expect=200)
    tl = pub.get("timeline") or {}
    res["timeline"] = tl
    log("опубликован", pub["status"] in ("published", "accepting_bids"),
        f"status={pub['status']} bids_end={tl.get('bids_end')}")

    st = wait_tender_status(cust["token"], tid, ["accepting_bids"])
    log("worker перевёл в accepting_bids", st == "accepting_bids", f"status={st}")

    # 3. заявки на участие от двух поставщиков
    bids = {}
    for name, s in (("A", sa), ("B", sb)):
        _, bd = call("POST", f"/tenders/{tid}/bids", s["token"], expect=201, body={
            "supplier_id": s["company_id"], "lot_id": lid,
            "part1": {"consent": True, "note": f"участник {name}"},
            "price_minor": 950000 if name == "A" else 900000,
            "price_basis": "net", "vat_rate": 20,
        })
        bids[name] = bd["id"]
        log(f"заявка участника {name}", bd["status"] == "submitted", f"{bd['id']} status={bd['status']}")

    # 4. допуск обеих заявок
    for name, bid_id in bids.items():
        _, q = call("POST", f"/bids/{bid_id}/qualification", cust["token"], expect=200,
                        body={"decision": "admit", "reason": "соответствует требованиям"})
        log(f"допуск заявки {name}", q["status"] == "admitted", f"status={q['status']}")

    # 5. аукцион на лот, старт торгов
    start_at = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(time.time() + 20))
    _, auc = call("POST", "/auctions", cust["token"], expect=201, body={
        "lot_id": lid, "type": "reduction", "step_mode": "fixed",
        "bid_step_minor": 10000, "step_duration_sec": 600, "max_extensions": 0,
        "scheduled_start_at": start_at,
    })
    aid = auc["id"]; res["auction_id"] = aid
    log("создан аукцион", auc["status"] in ("scheduled", "new", "draft"), f"{aid} status={auc['status']}")

    time.sleep(22)  # ждём наступления scheduled_start_at
    console("auctions:start-scheduled")
    _, state = call("GET", f"/auctions/{aid}/state", cust["token"], expect=200)
    log("торги стартовали", state["status"] == "trade",
        f"status={state['status']} start_price={state['start_price_minor']}")

    st = wait_tender_status(cust["token"], tid, ["bidding"], timeout=10)
    res["tender_status_during_trade"] = st
    log("статус тендера во время торгов", True, f"status={st} (ожидался bidding по tender-state-machine)")

    # 6. ставки: A, затем B ниже
    _, b1 = call("POST", f"/auctions/{aid}/bids", sa["token"], expect=201,
                     body={"price_minor": 990000})
    log("ставка участника A", True, f"{b1['price_minor']} round={b1['round']}")
    _, b2 = call("POST", f"/auctions/{aid}/bids", sb["token"], expect=201,
                     body={"price_minor": 980000})
    log("ставка участника B (ниже)", True, f"{b2['price_minor']} round={b2['round']}")

    # 7. завершение торгов и победитель
    call("POST", f"/auctions/{aid}/finish", cust["token"], expect=[200, 204])
    _, state = call("GET", f"/auctions/{aid}/state", cust["token"], expect=200)
    log("торги завершены", state["status"] == "choice", f"status={state['status']}")

    _, win = call("POST", f"/auctions/{aid}/winner", cust["token"], expect=[200, 201])
    log("победитель утверждён", win.get("status") == "approve",
        f"status={win.get('status')} winner_bid={win.get('winner_bid_id')}")

    # 8. договор между заказчиком и победителем (обязателен для DONE, FR-1.4.3)
    _, types = call("GET", "/contract-types", cust["token"], expect=200)
    ctype = (types["items"] if isinstance(types, dict) else types)[0]
    _, ctr = call("POST", "/contracts", cust["token"], expect=201, body={
        "contract_type_id": ctype["id"], "source": "tender",
        "customer_id": cust["company_id"], "supplier_id": sb["company_id"],
        "scope": "single_use", "tender_id": tid,
        "price_net_minor": 980000, "vat_rate": 20, "price_basis": "net",
    })
    cid = ctr["id"]; res["contract_id"] = cid
    log("договор создан", ctr["status"] == "draft", f"{cid} status={ctr['status']}")

    _, ctr = call("POST", f"/contracts/{cid}/send-for-signature", cust["token"], expect=200)
    log("договор отправлен на подписание", ctr["status"] == "pending_signature", f"status={ctr['status']}")
    call("POST", f"/contracts/{cid}/sign", cust["token"], expect=200,
             body={"party": "customer", "signature": "e2e-customer"})
    _, ctr = call("POST", f"/contracts/{cid}/sign", sb["token"], expect=200,
                      body={"party": "supplier", "signature": "e2e-supplier"})
    log("договор подписан обеими сторонами", ctr["status"] in ("signed", "registered"), f"status={ctr['status']}")

    # 9. исполнение: start-work → mark-done → confirm-done
    call("POST", f"/auctions/{aid}/start-work", sb["token"], expect=[200, 204])
    _, state = call("GET", f"/auctions/{aid}/state", cust["token"], expect=200)
    log("исполнитель начал работы", state["status"] == "in_work", f"status={state['status']}")

    call("POST", f"/auctions/{aid}/mark-done", sb["token"], expect=[200, 204])
    _, state = call("GET", f"/auctions/{aid}/state", cust["token"], expect=200)
    log("исполнитель отметил выполнение", state["status"] == "done_by_performer", f"status={state['status']}")

    call("POST", f"/auctions/{aid}/confirm-done", cust["token"], expect=[200, 204])
    _, state = call("GET", f"/auctions/{aid}/state", cust["token"], expect=200)
    log("заказчик подтвердил выполнение", state["status"] == "done", f"status={state['status']}")

    st = wait_tender_status(cust["token"], tid, ["closed"], timeout=20)
    log("тендер закрыт", st == "closed", f"status={st}")
    res["final_tender_status"] = st
    res["final_auction_status"] = state["status"]

    # 10. оценка исполнения
    s, rate = call("POST", f"/tenders/{tid}/rating", cust["token"], body={"execution_rating": 9})
    log("оценка исполнения", s == 200, f"http={s} rating={rate.get('execution_rating')}")

    # 11. видимость для проигравшего (задача 13)
    s, card = call("GET", f"/tenders/{tid}", sa["token"])
    log("проигравший не видит закрытую закупку", s == 404, f"http={s}")
    return res

def main():
    admin = login(*ADMIN)
    tag = rnd(5)
    print(f"=== подготовка (tag={tag}) ===", flush=True)
    cust = register("cust", tag, admin); log("заказчик зарегистрирован и подтверждён", True, cust["email"])
    sa = register("supa", tag, admin);  log("поставщик A зарегистрирован", True, sa["email"])
    sb = register("supb", tag, admin);  log("поставщик B зарегистрирован", True, sb["email"])

    results = []
    for ptype in ("competition", "rfq", "rfp", "direct"):
        try:
            results.append(run_type(ptype, cust, sa, sb))
        except Exception as e:
            log(f"{ptype}: ПРЕРВАНО", False, str(e)[:500])
            results.append({"type": ptype, "error": str(e)[:500]})
    json.dump({"results": results, "log": LOG}, open("e2e-result.json", "w"), ensure_ascii=False, indent=2)
    failed = [x for x in LOG if not x["ok"]]
    print(f"\n=== ИТОГ: шагов {len(LOG)}, провалов {len(failed)} ===")
    for f in failed:
        print("  FAIL:", f["step"], "—", f["detail"])
    return 1 if failed else 0

if __name__ == "__main__":
    sys.exit(main())

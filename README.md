<div id="warehouse-reservation-system-ua"></div>

🇺🇦 Українська | [🇬🇧 English](#warehouse-reservation-system)

# Система резервування складу

Подієво-орієнтована система резервування інвентарю на Laravel 13.

---

## Швидкий старт

**1. Створити бази даних (MariaDB/MySQL):**
```sql
CREATE DATABASE antoshka;
CREATE DATABASE antoshka_test; -- для тестів
```

**2. Налаштувати `.env`** (скопіювати з `.env.example` та змінити):
```dotenv
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=antoshka
DB_USERNAME=root
DB_PASSWORD=your_password

SUPPLIER_BASE_URL=http://localhost:8000
```

**3. Встановити залежності та запустити:**
```bash
composer install
php artisan migrate
php artisan db:seed          # заповнює inventories тестовими SKU
php artisan serve            # термінал 1 — веб-сервер (порт 8000)
php artisan queue:work       # термінал 2 — обробка черги
```

> **Примітка:** Фейкові ендпоінти постачальника знаходяться за адресами `/supplier/reserve`
> та `/supplier/status/{ref}` всередині того самого додатку (див. `routes/web.php`).
> `SUPPLIER_BASE_URL=http://localhost:8000` у `.env` вказує сервіс на них.
> Перша перевірка статусу повертає `"delayed"`, друга — `"ok"`,
> що автоматично відтворює повний шлях з повторними спробами.

---

## API

| Метод | Шлях | Опис |
|-------|------|------|
| `POST` | `/api/order` | Створити замовлення |
| `GET`  | `/api/orders/{id}` | Отримати замовлення за ID |
| `GET`  | `/api/inventory/{sku}/movements` | Історія рухів запасу для SKU |

### POST /api/order

```json
{ "sku": "ABC123", "qty": 3 }
```

Відповідь `201`:

```json
{
  "id": 1,
  "sku": "ABC123",
  "qty": 3,
  "status": "pending",
  "supplier_ref": null,
  "supplier_attempts": 0,
  "created_at": "...",
  "updated_at": "..."
}
```

---

## Потік подій

```
POST /api/order
  │
  ├─ Замовлення створено (status = pending)
  │
  └─ Подія OrderCreated відправлена
       │
       └─ Listener ReserveInventory (у черзі, асинхронно)
            │
            ├─ [запасів достатньо]
            │     ├─ qty_reserved += qty
            │     ├─ InventoryMovement (type=reserve)
            │     └─ order.status = reserved  ✓
            │
            └─ [запасів недостатньо]
                  ├─ POST /supplier/reserve → { accepted, ref }
                  │
                  ├─ [accepted=false]
                  │     └─ order.status = failed  ✗
                  │
                  └─ [accepted=true]
                        ├─ order.status = awaiting_restock
                        ├─ order.supplier_ref = ref
                        │
                        └─ Job CheckSupplierDelivery (затримка 15 с)
                               │
                               ├─ GET /supplier/status/{ref}
                               │
                               ├─ "ok"
                               │     ├─ qty_on_hand += qty
                               │     ├─ qty_reserved += qty
                               │     ├─ InventoryMovement (type=restock)
                               │     └─ order.status = reserved  ✓
                               │
                               ├─ "fail"
                               │     └─ order.status = failed  ✗
                               │
                               └─ "delayed"  (attempt < 2)
                                     ├─ order.supplier_attempts++
                                     └─ повторний запуск (затримка 15 с, attempt+1)
                                           └─ після attempt 2 → order.status = failed  ✗
```

---

## Життєвий цикл статусу замовлення

```
pending → reserved          (щасливий шлях, є на складі)
pending → awaiting_restock  (запасів немає, постачальник прийняв)
pending → failed            (постачальник відхилив)
awaiting_restock → reserved (постачальник доставив)
awaiting_restock → failed   (постачальник відмовив або delayed × 3)
```

---

## Стратегія обробки помилок

| Шар | Стратегія |
|-----|-----------|
| **Валідація HTTP** | `StoreOrderRequest` повертає 422 з JSON-описом помилок при некоректному введенні |
| **Гонка за інвентар** | `DB::transaction` + `SELECT … FOR UPDATE` запобігає подвійному резервуванню |
| **HTTP-помилки постачальника** | `SupplierService` повертає `"fail"` за замовчуванням при будь-якому non-2xx або відсутньому полі |
| **"delayed" від постачальника** | `CheckSupplierDelivery` повторно відправляє себе з `attempt+1` та затримкою 15 с; після 3 перевірок — `failed` |
| **Збої job** | `$tries = 1` — повторні спроби керуються вручну. При необробленому винятку запис потрапляє до `failed_jobs` |
| **Збої listener** | Черговий listener також пише до `failed_jobs`; замовлення залишається `pending` для подальшого перезапуску |

---

## Що покращити у продакшн-версії

### Надійність
- **Ідемпотентні ключі** у викликах до постачальника — безпечний повтор при мережевих збоях без подвійного замовлення.
- **Патерн Outbox** для гарантованої доставки навіть при збої процесу між комітом БД та публікацією в чергу (`$afterCommit = true` вже встановлено, але Outbox надійніший при інфраструктурних збоях).
- **Dead-letter queue** та сповіщення при потраплянні job до `failed_jobs`.

### Коректність
- **Оптимістичне блокування** (колонка `order.version`) або обмеження на рівні БД для запобігання паралельних переходів стану одного замовлення.
- **Термін дії резервування** — якщо замовлення довго в `awaiting_restock`, звільняти резерв.

### Спостережуваність
- Структуроване логування (канал на домен: `order`, `inventory`, `supplier`) з correlation ID.
- Метрики глибини черги, затримки job, часу відповіді постачальника.

### Архітектура
- Винести `InventoryService` для централізації логіки мутацій та незалежного тестування.
- Перенести інтеграцію з постачальником за контракт `SupplierContract` — фейкова та реальна реалізації стають взаємозамінними.
- Додати `OrderStateMachine` для явного та аудитованого управління переходами.
- `CheckSupplierDelivery` зараз робить забагато: HTTP-виклик, управління повторами, оновлення інвентарю, запис рухів, зміна статусу. Логіку інвентарю варто винести до моделі `Inventory` або окремого сервісу.

### Інфраструктура
- Перехід з MariaDB на PostgreSQL; використання advisory locks (`pg_advisory_xact_lock`) для рядків інвентарю.
- Redis для черги та кешу замість БД.
- Горизонтальне масштабування воркерів з окремими чергами для `orders` та `supplier-checks`.

---

## Запуск тестів

```bash
php artisan test
# або з покриттям
php artisan test --coverage
```

Набори тестів:

| Файл | Покриття |
|------|----------|
| `OrderCreationTest` | Створення замовлення, валідація, `GET /api/orders/{id}` |
| `InventoryReservationTest` | Достатній запас, недостатній запас, відмова постачальника, ендпоінт рухів |
| `CheckSupplierDeliveryTest` | ok / fail / delayed / delayed×2 / delayed→ok / невідомий статус |

---
---


[🇺🇦 Українська](#warehouse-reservation-system-ua) | 🇬🇧 English

# Warehouse Reservation System

Event-driven inventory reservation built on Laravel 13.

---

## Quick Start

**1. Create databases (MariaDB/MySQL):**
```sql
CREATE DATABASE antoshka;
CREATE DATABASE antoshka_test; -- for tests
```

**2. Configure `.env`** (copy from `.env.example` and update):
```dotenv
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=antoshka
DB_USERNAME=root
DB_PASSWORD=your_password

SUPPLIER_BASE_URL=http://localhost:8000
```

**3. Install dependencies and run:**
```bash
composer install
php artisan migrate
php artisan db:seed          # seeds inventories with sample SKUs
php artisan serve            # terminal 1 — web server (port 8000)
php artisan queue:work       # terminal 2 — queue worker
```

> **Note:** The fake supplier endpoints live at `/supplier/reserve` and
> `/supplier/status/{ref}` inside the same app (see `routes/web.php`).
> `SUPPLIER_BASE_URL=http://localhost:8000` in `.env` points the service at them.
> The first status check returns `"delayed"`, the second returns `"ok"`,
> which exercises the full retry path automatically.

---

## API

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/api/order` | Create an order |
| `GET`  | `/api/orders/{id}` | Fetch order by ID |
| `GET`  | `/api/inventory/{sku}/movements` | Stock movement history for a SKU |

### POST /api/order

```json
{ "sku": "ABC123", "qty": 3 }
```

Response `201`:

```json
{
  "id": 1,
  "sku": "ABC123",
  "qty": 3,
  "status": "pending",
  "supplier_ref": null,
  "supplier_attempts": 0,
  "created_at": "...",
  "updated_at": "..."
}
```

---

## Event Flow

```
POST /api/order
  │
  ├─ Order created (status = pending)
  │
  └─ OrderCreated event dispatched
       │
       └─ ReserveInventory listener (queued, async)
            │
            ├─ [stock available]
            │     ├─ qty_reserved += qty
            │     ├─ InventoryMovement (type=reserve)
            │     └─ order.status = reserved  ✓
            │
            └─ [stock insufficient]
                  ├─ POST /supplier/reserve → { accepted, ref }
                  │
                  ├─ [accepted=false]
                  │     └─ order.status = failed  ✗
                  │
                  └─ [accepted=true]
                        ├─ order.status = awaiting_restock
                        ├─ order.supplier_ref = ref
                        │
                        └─ CheckSupplierDelivery job (delay 15 s)
                               │
                               ├─ GET /supplier/status/{ref}
                               │
                               ├─ "ok"
                               │     ├─ qty_on_hand += qty
                               │     ├─ qty_reserved += qty
                               │     ├─ InventoryMovement (type=restock)
                               │     └─ order.status = reserved  ✓
                               │
                               ├─ "fail"
                               │     └─ order.status = failed  ✗
                               │
                               └─ "delayed"  (attempt < 2)
                                     ├─ order.supplier_attempts++
                                     └─ re-dispatch self (delay 15 s, attempt+1)
                                           └─ after attempt 2 → order.status = failed  ✗
```

---

## Order Status Lifecycle

```
pending → reserved          (happy path, stock on hand)
pending → awaiting_restock  (stock insufficient, supplier accepted)
pending → failed            (supplier rejected)
awaiting_restock → reserved (supplier delivered)
awaiting_restock → failed   (supplier failed or delayed × 3)
```

---

## Error Handling Strategy

| Layer | Strategy |
|-------|----------|
| **HTTP validation** | `StoreOrderRequest` returns 422 with JSON error bag on bad input |
| **Inventory race conditions** | `DB::transaction` + `SELECT … FOR UPDATE` prevents double-reservation |
| **Supplier HTTP errors** | `SupplierService` returns `"fail"` as the default status on any non-2xx or missing field; order is marked `failed` |
| **Supplier "delayed"** | `CheckSupplierDelivery` re-dispatches itself with `attempt+1` and a 15-second delay; after 3 total checks the order is marked `failed` |
| **Job failures** | `$tries = 1` — we manage retries explicitly. A Laravel `failed_jobs` record is written on any unhandled exception for later inspection |
| **Listener failures** | Queued listeners also write to `failed_jobs`; the order remains `pending` and can be re-processed after investigation |

---

## What Would Be Improved in Production

### Reliability
- **Idempotency keys** on supplier calls to safely retry network-level failures without double-ordering.
- **Outbox pattern** to guarantee exactly-once delivery even if the process crashes between DB commit and queue publish (`$afterCommit = true` is already set, but the outbox pattern is more robust under infrastructure failures).
- **Dead-letter queue** and alerting when jobs land in `failed_jobs`.

### Correctness
- **Optimistic locking** (`order.version` column) or database-level constraints to prevent concurrent state transitions on the same order.
- **Inventory reservation expiry** — if an order stays `awaiting_restock` too long, release the reservation to prevent phantom holds.

### Observability
- Structured logging (channel per domain: `order`, `inventory`, `supplier`) with correlation IDs.
- Metrics for queue depth, job latency, supplier response times.

### Architecture
- Extract `InventoryService` to centralise all mutation logic and make it independently testable.
- Move supplier integration behind a proper adapter/contract (`SupplierContract`) so the fake and real implementations are swappable without touching business logic.
- Add a dedicated `OrderStateMachine` to make transitions explicit and auditable.
- `CheckSupplierDelivery` currently handles too much: HTTP call, retry management, inventory update, movement recording, and order status change. Inventory mutation logic should be extracted to the `Inventory` model or a dedicated service to respect SRP.

### Infrastructure
- Switch from MariaDB to PostgreSQL; use advisory locks (`pg_advisory_xact_lock`) for inventory rows to avoid table-level locking under high concurrency.
- Use Redis for the queue and cache to reduce DB load.
- Horizontal queue workers with separate queues for `orders` and `supplier-checks`.

---

## Running Tests

```bash
php artisan test
# or with coverage
php artisan test --coverage
```

Test suites:

| File | Coverage |
|------|----------|
| `OrderCreationTest` | Order creation, validation, `GET /api/orders/{id}` |
| `InventoryReservationTest` | Sufficient stock, insufficient stock, supplier reject, movements endpoint |
| `CheckSupplierDeliveryTest` | ok / fail / delayed / delayed×2 / delayed→ok / unknown status |

---

[🇺🇦 Українська](#ua)

# Amotan: Project Description & Technical Disclosure

## 1. Project description

**Amotan** is an offline-first budgeting app (installable Progressive Web App) for students, workers, families and barkadas. It tells you how much you can **safely spend until payday**, warns you **before** money problems happen, and helps groups save together **fairly**. It works with no internet and syncs to the cloud when you're back online. Its AI assistant, **Amo**, runs on the user's own phone.

**Tagline:** *Make it to payday, every time.*

### The problems Amotan solves

| Problem | How Amotan solves it |
|---|---|
| **Money runs out before payday.** Most apps only show what was already spent. | **Safe to spend:** what you can use until the next payday after bills and savings, plus a daily allowance and a payday planner. |
| **No signal, no app.** Cloud-only apps fail on commutes, in the province, or when mobile data runs out. | **Offline-first:** data is stored on the device. Changes made offline wait in an outbox and sync automatically when online. Each change is applied only once. |
| **Money shocks come without warning:** late salary, emergencies, higher bills. | **Domino Check:** a stress test that shows the first day you'd go short, which bills tip over, and the buffer you need. |
| **Group money is awkward:** who paid, who owes, who can't afford it this month. | **Barkada plans:** chat room, shared notes, calendar, to-dos, expense splitting, settle-up in the fewest payments, and **private fair-share** contributions that no other member can see. |
| **Connecting with friends needs emails or phone numbers.** | **Personal QR:** every user gets an auto-generated code (e.g. `AMO-ZLN7ZT`) to share as an image, link or code. Scanning it makes you buddies who can invite each other to plans in one tap. |
| **Cloud AI sees your finances.** Asking a cloud chatbot means sending private data to a third party. | **Local AI:** the assistant and receipt reading (OCR) run on the device. No third-party AI API is used. |
| **Budgeting feels stressful.** | **Amo, the mascot:** 17 moods and animations that react to your money (cheering, worried, dizzy, sleeping when offline). Tap Amo for a reaction. |

### Who it's for
- **Students:** make the allowance last.
- **Workers:** payday-to-payday planning.
- **Families:** bills, emergencies, shared phones (logout wipes local data).
- **Barkadas:** trips, ambagan and group goals.

### Key features
1. Dashboard with safe to spend, daily allowance and spending status
2. Offline-first storage with automatic cloud sync and a Sync center (pending/failed items)
3. Domino Check stress tests (salary delay, surprise expense, bills up, income cut)
4. Barkada plans with chat, notes, calendar, to-dos, splitting, settle-up and private fair share
5. Auto-generated personal QR and buddies
6. On-device AI assistant ("Can I afford…?", "Where did my money go?", what-ifs, quick logging like "lunch 120")
7. On-device receipt scanning (OCR)
8. Bills, debts, savings goals, budgets, alerts and a payday planner
9. Secure sign-in and sign-up: password rules (8–72 characters with a letter and a number), confirm password, show/hide toggle
10. Amo mascot with 17 moods, tap reactions and reduced-motion support

---

## 2. Technical disclosure

### 2.1 Architecture overview

```
Phone / browser (PWA)
├─ Vue 3 app (Vite build)
├─ Service Worker: app shell + static files + last-known data
├─ IndexedDB "amotan": kv cache + outbox
├─ On-device AI: WebLLM (WebGPU, Web Worker) or rule-based "Instant mode"
├─ On-device OCR: Tesseract.js (self-hosted files)
├─ On-device QR: qrcode (render) + BarcodeDetector (scan)
└─ Domino Check simulator (pure JavaScript, on device)

Server (Laravel)
├─ REST API /api/* (Laravel Sanctum bearer tokens)
├─ Idempotency middleware (Idempotency-Key → sync_receipts)
├─ Finance services: safe-to-spend, projections, payday plan,
│  budgets, alerts, shared plans, settle-up
└─ MySQL / MariaDB
```

- **Offline flow:** GET responses are cached in IndexedDB and returned offline. Writes made offline go to an IndexedDB **outbox** and are replayed when online. Each write carries a unique `Idempotency-Key`, and the server stores the result in `sync_receipts` so a retried write is never applied twice. Logging in, registering and AI requests are not queued.
- **AI grounding:** the language model never does the maths. The server's `FinanceService` and the on-device simulator compute every figure. The model (or Instant mode) only explains those facts.
- **Privacy:** financial data syncs only to the user's own Amotan account on the app's server. Logout clears the IndexedDB cache and outbox, the service worker's cached API data and the login token on the device.

### 2.2 Backend (server)

| Technology | Version | License | Purpose |
|---|---|---|---|
| PHP | 8.5 (requires ≥ 8.3) | PHP License | Server language |
| Laravel Framework | 13.35 | MIT | Web/API framework, validation, Eloquent ORM, migrations |
| Laravel Sanctum | 4.3 | MIT | API token authentication |
| Laravel Tinker | 3.0 | MIT | Developer console |
| MySQL 8 / MariaDB 10.4+ (tested on MariaDB 10.6) | — | GPL-2.0 | Database |

### 2.3 Frontend (app)

| Library | Version | License | Purpose |
|---|---|---|---|
| Vue | 3.5 | MIT | UI framework |
| Vue Router | 4.6 | MIT | Screens/navigation |
| Pinia | 4.0 | MIT | State management (auth, sync status) |
| Tailwind CSS (+ @tailwindcss/vite) | 4.3 | MIT | Styling |
| Chart.js | 4.5 | MIT | Charts (spending, balance projections) |
| qrcode | 1.5 | MIT | Generates the personal QR on the device |
| Vite (+ @vitejs/plugin-vue, laravel-vite-plugin) | 8.3 | MIT | Build tooling |

### 2.4 On-device AI & OCR

| Component | Version | License | Purpose |
|---|---|---|---|
| WebLLM (`@mlc-ai/web-llm`) | 0.2.85 | Apache-2.0 | Runs a language model in the browser on WebGPU, in a Web Worker |
| Qwen 2.5 0.5B / 1.5B Instruct (MLC 4-bit builds) | — | Apache-2.0 | Default models (0.5B on phones, 1.5B on laptops) |
| Qwen 2.5 3B Instruct (optional) | — | Qwen Research License | Optional larger model chosen in Settings |
| Llama 3.2 1B Instruct (optional) | — | Llama 3.2 Community License | Optional alternative model |
| Tesseract.js | 7.0 | Apache-2.0 | Receipt OCR on the device |
| `@tesseract.js-data/eng` | 1.0 | MIT (package) | English OCR language data, self-hosted in `public/vendor/tesseract` |
| Built-in "Instant mode" engine | — | Project code | Rule-based understanding and answers (`resources/js/ai/parse.js`, `assistant.js`) when WebGPU is unavailable or the model is off |
| Ollama (optional, self-hosted) | — | MIT | Off by default (`AI_SERVER_DRIVER=none`). If enabled, uses a model on the operator's own server |

**Disclosure about model downloads:** when a user turns on the full AI model, WebLLM downloads the model weights once from **Hugging Face** (`huggingface.co/mlc-ai/...`) and the WebGPU runtime library from **GitHub** (`raw.githubusercontent.com/mlc-ai/binary-mlc-llm-libs`). Both are cached by the browser. **No user data is sent** during the download. After that, the model runs fully offline. The OCR engine is served from the app's own domain.

**No cloud AI APIs** (OpenAI, Google, Anthropic, etc.) are used anywhere. Browser speech recognition is intentionally not used, because Chrome sends audio to Google.

### 2.5 Browser (local) APIs used

| Web API | Used for |
|---|---|
| Service Worker + Cache Storage | Installable PWA, offline app shell, cached assets and last-known data |
| IndexedDB | Local database: data cache (`kv`) and offline outbox (`outbox`) |
| localStorage | Login token, settings, AI preferences |
| `navigator.onLine` + online/offline events | Detect connectivity and trigger sync |
| WebGPU (`navigator.gpu`) | Runs the on-device language model |
| Web Workers | Keep the AI model off the UI thread |
| BarcodeDetector + `getUserMedia` (camera) | In-app QR scanning on the device (manual code entry as fallback) |
| Web Share API (`navigator.share` / `canShare`) | Share the QR image or link |
| Clipboard API | Copy the personal code or link |
| Storage API (`navigator.storage.estimate`) | Show storage used by the AI model |
| `URL.createObjectURL` | Download the QR image, preview receipts |
| `matchMedia` (prefers-reduced-motion, dark mode) | Accessibility and theme |
| Web App Manifest | Home-screen install, icons, theme color |

### 2.6 Original work (built by the team)
- **Amo mascot:** original SVG artwork with 17 moods, generated in code (`resources/js/mascot.js`). App icons are rendered from it.
- **Domino Check simulator:** `resources/js/sim/domino.js`.
- **Offline outbox and sync engine:** `resources/js/offline.js`, `sync.js`, `api.js`. Server side: `app/Http/Middleware/IdempotentRequest.php`.
- **Finance engine:** `app/Services/FinanceService.php`, `PaydayPlanner.php`, `BudgetGenerator.php`, `AlertService.php`, `SharedPlanService.php`.
- **Personal QR and buddies, private fair share, barkada chat, notes and calendar, Instant-mode AI rules:** all project code.
- No third-party UI kits, icon packs or stock images. Icons are inline SVG and fonts are the device's system fonts.

### 2.7 Development, testing & tooling

| Tool | License | Purpose |
|---|---|---|
| PHPUnit 12 | BSD-3-Clause | Backend tests (31 feature tests, 221 assertions, all passing) |
| Vitest 5 | MIT | Frontend unit tests (39 tests, all passing) |
| Laravel Pint | MIT | PHP code style |
| Faker, Mockery, Collision, Pail, Pao | MIT / BSD-3-Clause | Test data, mocking, error output, logs |
| sharp | Apache-2.0 | Generates PWA icons from the Amo SVG |
| concurrently | MIT | Runs dev processes together |

### 2.8 Promotional materials tooling (not part of the app)

| Tool | Purpose |
|---|---|
| Playwright (Apache-2.0) + Chromium | Records real app footage and screenshots |
| FFmpeg (LGPL/GPL) | Edits the promo video (animations, transitions) |
| edge-tts (open-source Python package) | Voiceover. **Note:** this uses Microsoft Edge's online text-to-speech service. It is used only to make the video, never in the app. |
| Pillow, python-pptx | Video graphics and the pitch deck |
| LocalTunnel / Cloudflare Tunnel | Temporary demo links (`amotan-ai.loca.lt`), only while the dev server runs |

### 2.9 Security & data handling
- Passwords are hashed by Laravel (bcrypt). Sign-up requires 8–72 characters with a letter and a number. Emails are stored lowercase.
- API access uses Sanctum bearer tokens. Plan chat, notes and events are visible to members only, and outsiders get 404.
- Fair-share amounts are private to each member. Buddies see only names, never balances.
- Logout wipes on-device financial data (IndexedDB cache and outbox, cached API responses) and the login token.
- Demo credentials (`demo@budget.test` / `password`) are for local demonstration only.

### 2.10 Current limitations (honest disclosure)
- The public link is temporary. A permanent `amotan-ai` domain requires hosting (planned).
- The full AI model needs WebGPU (Chrome/Edge 113+, Safari 18+). Other devices use Instant mode automatically.
- QR scanning with the camera needs BarcodeDetector (Chrome/Edge on Android, desktop). On iPhone, users enter or paste the code, or open the shared link.
- Account creation and joining a new plan need internet. Everything else works offline.
- Amo gives general budgeting help, not licensed financial advice.

# AI Budget Planner

A mobile-first, installable PWA that helps people make it to payday. It plans each paycheck, tracks spending, warns before overspending and answers money questions with an AI that runs **on the user's own device**.

**Stack:** Laravel 13 (PHP 8.5) · MySQL/MariaDB · Vue 3 + Vite + Tailwind 4 · WebLLM (on-device LLM) · Tesseract.js (on-device OCR)

## Features

| Area | What it does |
|---|---|
| **Smart budgeting** | Builds a monthly budget from income, fixed bills, savings goals and the last 3 months of spending (an adaptive 50/30/20). Editable per category. |
| **Expense tracking** | Manual entry, quick-add text ("coffee 4.50 at Starbucks"), chat ("spent 18 on pizza yesterday"), or receipt scanning. Auto-categorised by keywords. |
| **AI assistant ("Penny")** | Answers "Can I afford…?", "How much can I spend today?", "Where did my money go?", goals, debt and payday questions, and logs expenses from chat. |
| **Savings goals** | Progress, required monthly amount, estimated finish date, and a "what if I saved X/month?" calculator. |
| **Spending alerts** | Over budget, near the limit, month pace too high, *money will run out before payday*, bills due soon, and shared-plan invitations. |
| **Payday planner** | Splits each paycheck into bills → debts → essentials → savings → buffer, then a daily allowance. Surplus goes to the highest-interest debt. |
| **Bills & debts** | Due dates, "mark paid" (logs the expense, reduces the debt), avalanche ordering, payoff estimates. |
| **Plan together** | Shared plans (outings, trips, household pots, group goals) that can be **🔒 Private** or **👥 Group**. Invite people by **QR code**, link, 8-character code or email. Members add contributions and shared expenses, follow a checklist, then **settle up** with the fewest payments. Each member's GCash/Maya handle is shown, and either side can record a payment. |
| **PWA** | Installable on Android, iOS and desktop. Offline app shell, offline last-known data, and home-screen shortcuts. |

## The AI runs on the device

- **Language model:** [WebLLM](https://github.com/mlc-ai/web-llm) runs Qwen 2.5 / Llama 3.2 in the browser on WebGPU, inside a Web Worker. The model downloads once (about 400 MB to 2 GB, depending on the model chosen in Settings), is cached by the browser, and then works offline. Phones default to Qwen 2.5 0.5B; laptops default to 1.5B.
- **Grounded numbers:** the model never does the maths. `app/Services/FinanceService.php` calculates every figure (safe to spend, affordability, projections). The model gets those facts plus a draft answer and only rephrases them.
- **Instant mode:** devices without WebGPU, or users who turn the AI off, get the same answers from the rule-based engine (`resources/js/ai/parse.js` + `assistant.js`). Every feature works on every device.
- **Receipt OCR:** Tesseract.js and its English language data are self-hosted in `public/vendor/tesseract` (copied from `node_modules` at install/build time). Receipt photos are read on the device and are only uploaded as an attachment when the user saves.
- **Optional self-hosted server model:** set `AI_SERVER_DRIVER=ollama` (plus `OLLAMA_URL` and `OLLAMA_MODEL`) to let users choose a model running on your own machine instead. No third-party AI API is used anywhere.
- **Not used on purpose:** browser speech recognition, because Chrome sends the audio to Google.

## Running it locally

Requirements: PHP 8.3+ (with `intl` and `pdo_mysql`), Composer, Node 20+, MySQL 8 or MariaDB 10.4+.

```bash
composer install
npm install            # also copies the OCR engine into public/vendor/tesseract
cp .env.example .env && php artisan key:generate
# set DB_* in .env, then create the database (e.g. ai_budget_planner)
php artisan migrate --seed
npm run build          # or `npm run dev` while developing
php artisan serve
```

Open http://127.0.0.1:8000 and log in with the demo account from the seeder: **demo@budget.test / password** (3 months of sample data, bills, debts and goals).

### On a phone

Service workers (installing and offline use) and WebGPU only work over **HTTPS**, or on `localhost`. To try it on a phone, put it behind HTTPS, for example with a tunnel:

```bash
php artisan serve --host=0.0.0.0 --port=8000
cloudflared tunnel --url http://localhost:8000   # or ngrok http 8000
```

Then open the HTTPS URL on the phone and choose **Install** (Android) or **Share → Add to Home Screen** (iOS). For production, deploy behind a normal HTTPS host and set `APP_URL`. Join links and QR codes use the domain the app is served from.

WebGPU support: Chrome/Edge 113+ (desktop and Android), Safari 18+ (iOS 18 / macOS). Other browsers get instant mode automatically.

## Tests

```bash
php artisan test   # 21 feature tests: auth, money math, payday planner, alerts, budgets, shared plans, settle-up, privacy
npm test           # 25 unit tests: amount/date/intent parsing, receipt extraction
```

Feature tests run against MySQL (`ai_budget_planner_test`, see `phpunit.xml`), because the app uses MySQL date functions.

## Project map

```
app/Services/FinanceService.php     pay periods, safe-to-spend, projections, affordability, AI context
app/Services/BudgetGenerator.php    personalised budget
app/Services/PaydayPlanner.php      paycheck allocation + daily allowance
app/Services/AlertService.php       spending alerts (deduplicated by key)
app/Services/SharedPlanService.php  shared totals + fewest-payments settle-up
app/Http/Controllers/Api/*          REST API (Sanctum bearer tokens), routes/api.php
resources/js/ai/engine.js           WebLLM loader (WebGPU detection, model choice, worker)
resources/js/ai/assistant.js        intent → grounded facts → on-device LLM or built-in answer
resources/js/ai/parse.js            rule-based NLU (amounts, dates, intents, transactions)
resources/js/ai/receipt.js          on-device OCR + receipt field extraction
resources/js/pages/*                Vue screens; App.vue is the mobile shell (bottom tabs)
public/sw.js, manifest.webmanifest  PWA
```

## Local test accounts

Created during development in the local database (not part of the seeder):

- `onboarding@budget.test` / `password123`: went through the onboarding wizard, owns the "Beach day" shared plan
- `friend@budget.test` / `password123`: joined that plan by code

Reset everything with `php artisan migrate:fresh --seed`.

## Not financial advice

The assistant gives general budgeting help only. For investment, tax or legal decisions, users should talk to a licensed professional.

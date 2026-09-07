# MetalVal AI Assistant Demo

Public, sanitized demo of a B2B product-selection assistant for industrial product cards.

Flow:

**product question → clarification → technical requirements → lead qualification → sales handoff**

## What this demo shows

- Assistant widget embedded in a product card
- Rule-based / knowledge-assisted dialog flow
- Product context passed from the page into the assistant
- FAQ matching from JSON
- Extraction of dimensions, quantity and RAL values
- Clarification of drawings / project documentation
- Lead qualification
- Lead capture into a local CSV file
- Server-side session state
- Neutral privacy notice with offer link

## Stack

- PHP 8+
- Vanilla JavaScript
- JSON knowledge base
- Server-side state
- CSV lead export
- No external AI API required

## Run locally

```bash
php -S 127.0.0.1:8080
```

Open:

```text
http://127.0.0.1:8080/
```

## Demo scenario

```text
Сергей
Нужны ревизионные люки
10 люков 1480x2960x640 мм RAL 4050 и 5 люков 1280x1280x340 мм RAL 3060
Чертежей нет, есть проектная документация
Да. Как скоро ответит менеджер?
Юридическое лицо
ООО Вега
Усть-Илимск, Магаданская область
Сергей Валуев
Менеджер ОМТС
omts@example.com
```

The saved demo lead appears in:

```text
storage/leads.csv
```

## Repository structure

```text
.
├── index.html
├── api/
│   └── assistant.php
├── assets/
│   ├── css/
│   │   └── assistant.css
│   └── js/
│       └── assistant.js
├── data/
│   └── faq.example.json
├── docs/
│   └── architecture.md
├── storage/
│   └── sessions/
├── .gitignore
├── SECURITY.md
└── README.md
```

## Important

This repository is intentionally sanitized. Do not add:

- production customer dialogs;
- internal prices;
- private knowledge files;
- SMTP credentials;
- API keys;
- production sessions;
- real customer names, emails or phones.

The goal is to demonstrate product logic and UX without exposing production infrastructure.

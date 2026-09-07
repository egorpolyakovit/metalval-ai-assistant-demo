# Architecture

```text
Product card
   |
   | product context
   v
Assistant widget (Vanilla JS)
   |
   | POST JSON
   v
/api/assistant.php
   |
   +--> /data/faq.example.json
   |
   +--> /storage/sessions/<session>.json
   |
   +--> /storage/leads.csv
```

## Principle

The browser owns UI only:
- open / close
- render messages
- send user text
- typing state

The PHP backend owns:
- dialog state
- parsing
- intent decisions
- FAQ matching
- lead qualification
- persistence

## State machine

```text
ask_name
   ↓
main_question
   ↓
ask_drawing_status
   ↓
confirm_lead
   ↓
ask_client_type
   ↓
ask_company
   ↓
ask_city
   ↓
ask_contact_name
   ↓
ask_contact_position
   ↓
ask_contact
   ↓
completed
```

A production version can add an LLM for classification or response generation while keeping validation and lead capture deterministic.

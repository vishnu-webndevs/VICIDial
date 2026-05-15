# Updates (2026-05-07)

## Highlights

- WhatsApp campaign delivery status ab “Pending” me stuck nahi rahega: campaign status refresh pe Twilio se latest message status poll karke DB update hota hai.
- Inbox reply (SMS/WhatsApp) par aa raha 500 error fix: Part3 integration unavailable ho to direct Twilio (SMS/WhatsApp) fallback use hota hai.
- Analytics me “connected calls” aur “aaj ke calls” sahi dikhne lage: timezone range + connected status mapping improve.
- Onboarding optimize: provider save ke baad auto validate + numbers sync + default from set + agent number assignment; onboarding completion pe dashboard ready.
- Unified Inbox page ka unwanted reload/flicker fix.

## Frontend Changes (Web)

- Onboarding provider auto-setup:
  - Save Provider → auto: fetch numbers → sync numbers → default from_number set → test connection.
  - Optional WhatsApp From capture.
  - Agent create ke baad validated number auto-assign.
  - Auto-redirect bug fix: onboarding ab load pe dashboard redirect nahi karta; completion pe “Go to Dashboard” button.
  - File: f:\xampp\htdocs\vicidial\apps\web\src\app\onboarding\page.tsx

- Unified Inbox reload/flicker fix:
  - Thread list load loop fix (selectedThreadId dependency remove).
  - Channel change pe selection + composer clear.
  - File: f:\xampp\htdocs\vicidial\apps\web\src\app\conversations\page.tsx

- Analytics default date range + display fix:
  - Default range ab local timezone me calculate hota hai (UTC shift issue fix).
  - File: f:\xampp\htdocs\vicidial\apps\web\src\app\analytics\page.tsx

- Campaigns UX:
  - Edit Campaign Step-2 me “From Agent” auto-prefill (agent mapping se).
  - Default schedule window set: Mon-Fri 09:00-18:00.
  - File: f:\xampp\htdocs\vicidial\apps\web\src\app\campaigns\page.tsx

- WhatsApp Campaigns:
  - File upload handler typing fix (files access safe).
  - File: f:\xampp\htdocs\vicidial\apps\web\src\app\whatsapp-campaigns\page.tsx

## Backend Changes (API)

- WhatsApp campaign status refresh improvement:
  - `/api/v1/campaigns/{id}/status` call pe Twilio message status polling (queued/sent → delivered/read) + DB update.
  - File: f:\xampp\htdocs\vicidial\apps\api\app\Http\Controllers\Api\V1\CampaignController.php

- Inbox thread send 500 fix:
  - Part3 adapter production-gate throw kare to direct Twilio (SmsService/WhatsAppService) fallback se send.
  - Failure par 422 + proper message (500 avoid).
  - File: f:\xampp\htdocs\vicidial\apps\api\app\Http\Controllers\Api\V1\CorePhaseOneController.php

- Analytics connected call counting fix:
  - “Connected” ab status in (completed, answered, in_progress) count hota hai.
  - Files:
    - f:\xampp\htdocs\vicidial\apps\api\app\Services\Analytics\CampaignAnalyticsService.php
    - f:\xampp\htdocs\vicidial\apps\api\app\Http\Controllers\Api\V1\AnalyticsController.php

## Quick Verify

- Onboarding:
  - Provider step me Save Provider ke baad “Auto-validating & syncing numbers…” message.
  - Step-3 me agent create ke baad number assigned success.
- WhatsApp Campaign:
  - Campaign start → “Refresh Status” → Pending ka count delivered/read me move.
- Inbox:
  - WhatsApp thread open → reply send → 500 nahi aayega; success/422 with reason.
- Analytics:
  - `/analytics` open → last 7 days me calls + connected count show.


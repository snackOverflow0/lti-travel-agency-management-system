# Softphone capability and test report

| Capability | Status | Evidence |
|---|---|---|
| Contact lookup by number (only visible contacts) | PASS | Phase 7 API PHASE7 API SUMMARY 21/21 |
| Phone-app handoff (`tel:` link) — attempt recorded, never marked connected | PASS (automated); device handler needs a manual check | Phase 7 browser PHASE7 E2E SUMMARY 7/7 |
| Manual call logs, notes, dispositions, callbacks (tasks + reminders), CRM timeline | PASS | Phase 7; System flow F08 |
| Call history, filters, CSV, analytics (labelled unverified) | PASS | Phase 7 |
| Hold, mute, transfer, answer, record | Disabled with explanation | Phase 7 browser |
| Browser calls to ordinary phone numbers (PSTN) | **Unavailable** | No telephony provider identified |
| Incoming calls to the browser | **Unavailable** | Same |
| Call recording, voicemail | **Unavailable** | No provider; consent/retention policy would be required |

Real browser calling needs a provider the agency **already pays for** whose plan includes browser (WebRTC) calling or a SIP account usable from a WebRTC gateway. None has been identified; nothing is simulated. Please confirm in writing that this limitation is accepted for go-live.

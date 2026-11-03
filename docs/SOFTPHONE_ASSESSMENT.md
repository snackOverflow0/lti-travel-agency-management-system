# Softphone: why it seems nonfunctional, and what real calling would need

**What works today (tested, Phase 7: 21/21 API, 7/7 browser):** contact lookup by number, phone-app handoff (`tel:` link), manual call logs with notes and outcomes, callbacks with tasks and reminders, call history, analytics. Hold, mute, transfer, answer and record are visibly disabled.

**Why it feels nonfunctional:** it cannot place or receive a call itself. "Open phone app" hands the number to whatever app the computer or phone uses for `tel:` links (for example Phone Link on Windows, FaceTime on a Mac, a softphone app, or the phone dialler). If no such app is installed, nothing happens.

| Desired capability | Needs | Available at $0? |
|---|---|---|
| Outbound calls to ordinary numbers from the browser | A telephony provider with a browser SDK (WebRTC-to-PSTN gateway) or a SIP account plus a WebRTC-capable SIP gateway | Only if the agency **already pays** for such a service and its plan includes browser calling |
| Incoming calls to the browser | A phone number hosted by that provider, routed to the browser | Same |
| Microphone/speaker, answer, end, hold, mute, transfer | Provider SDK call states | Same |
| Caller identification | Provider webhooks with signature verification | Same |
| Call recording | Provider feature + consent and retention policy | Same, plus legal review |

**Not possible at $0 without a provider:** website hosting does not include phone service, and browsers cannot dial the public telephone network on their own. Browser-to-browser WebRTC would only connect two LTI users, not phone numbers, and reliable connections need a TURN relay that has no free hosted option.

**What I need to go further:** the name and plan of any phone service the agency already pays for (for example a VoIP desk phone service). I will check whether that plan includes browser calling or a SIP account usable from WebRTC, and implement only documented capabilities with real test calls. Until then, external dialing and manual logging remain the honest maximum.

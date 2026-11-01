# Phase 7 calling capability and softphone audit

## Prerequisites
Phases 0–6 verified by the regression suites (see TEST_RESULTS.md). Open: Phase 5 and Phase 6 restored screens are not yet connected and those phases are unapproved; Phase 7 uses their APIs only.

## Calling modes
| Mode | Status | Notes |
|---|---|---|
| A — External device dialing (`tel:`) | **Available** | Opens the device’s phone/calling app if one is registered. LTI records an attempt and cannot see whether the call connects; staff record the outcome. |
| B — Existing telephony service | **Unavailable** | None known. Website hosting does not include phone service. Integration would need an already-paid compatible provider and is not built. |
| C — Browser-to-browser WebRTC | **Not implemented** | Reliable connectivity needs a TURN relay; no free hosted option exists, and PHP polling signaling on shared hosting is fragile. It would not dial phone numbers. |
| D — Manual call logging | **Available** | Works with any phone, no added cost. |

**PSTN calling: unavailable.** No recordings, no voicemail, no live hold/mute/transfer.

## Revision 27 softphone features
| Feature | Before (prototype) | Now |
|---|---|---|
| Dial pad, number input, country | Simulated dialing | Live: keypad, country-aware normalisation, lookup |
| Contact search / caller info | Sample contacts | Live: only contacts the user may see; unknown numbers handled |
| Incoming, outgoing, active-call screens | Simulated states, timers | Removed; replaced by an honest handoff screen (no timer, no connected state) |
| Hold, mute, transfer, record, answer | Simulated | **Disabled** with explanation |
| Notes, disposition | Sample (`X.calls`) | Live (`call_notes`, `call_dispositions`) |
| Call history, missed calls | Sample | Live with filters, search, paging, CSV |
| Callback scheduling | Sample | Live: callback + linked task + queued reminder |
| Contact / trip association | Sample | Live (record-level access enforced) |
| Call reporting | Sample | Live; labelled as staff-entered, verified = 0 |
| Voicemail | Simulated inbox | Page states it is unavailable (no fake messages) |
| Recordings | Simulated | Not available; calls are never recorded |

# Hamnoor Completion Feedback Extension v1

## Purpose

Provide a generic, optional, response-time extension point after a **new** `participation.completed` domain fact has been emitted. Hamnoor does not know or depend on Rewards.

## Contract

- Domain event remains the source fact: `participation.completed` schema v1.
- A request-local copy of the emitted payload is retained only until the PHP request ends.
- Transport adapters may call `Hamnoor_Domain_Events::completion_extension_feedback($entity_type, $entity_id)`.
- Add-ons may append namespaced data through `hamnoor_completion_extension_feedback`.
- Hamnoor returns that opaque object as `extension_feedback` in successful AJAX completion responses.
- Browser transport dispatches one generic DOM event: `hamnoor:completion-feedback`.
- Hamnoor neither interprets nor persists add-on feedback.

## Covered completion transports

- Quran auto-done claim response.
- Quran classic AJAX completion.
- Quran reading-screen completion.
- Direct Dua completion.
- Pending Dua -> completed transition.
- Numeric Dhikr participation (the add-on decides whether to surface anything).
- Personal Quran completion.

## Replay property

Feedback exists only if this request emitted a new domain completion event. Idempotent/replayed application requests that do not emit a new `participation.completed` event return no completion-extension feedback. This prevents duplicate reward UI at the Hamnoor layer without making Hamnoor aware of rewards.

## Redirecting completion surfaces

Personal Quran completion normally redirects after success. When any completion-extension feedback is present, the generic reader transport waits about 2.2 seconds instead of 0.8 seconds so the user can actually perceive the feedback. With no extension feedback, the established 0.8-second redirect remains unchanged.

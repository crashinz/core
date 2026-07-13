# Framework Core

Framework Core owns shared runtime composition contracts that are not business
behavior for one room subsystem.

`RuntimeDiagnostics` owns the opt-in schema-versioned diagnostics contract.

`RuntimeRequestClient`, added in Build 000043 Part 5, owns authenticated
active-room JSON transport and common response validation. It classifies
redirect/session, CSRF, HTML/content-type, empty/invalid JSON, application,
timeout, cancellation, network, and HTTP failures without exposing credentials,
cookies, raw HTML, private paths, SDP, or ICE.

Endpoint runtimes and host adapters retain request business semantics, UI
behavior, and retry decisions. RuntimeRequestClient never automatically retries
non-idempotent work.

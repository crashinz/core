# Gesture Maker first-party extension

This repository-owned extension owns only the Create/Edit Gesture management
presentation, editor UI, previews, tools, user-facing AGST package
presentation, and Catie attribution/explanation.

Mandatory core remains the sole owner of gesture identity, ownership,
authorization, canonical text and history snapshots, protected delivery, AGST
and archive validation, immutable package generations, provenance, storage,
concurrency, idempotency, Tool Logs, privacy, moderation, and security.

The extension receives only the deny-by-default services declared in
`extension.json`. It cannot select executable paths, access the database or
private storage, mint identity, parse untrusted archives, authorize itself, or
deliver protected bytes.

# Private Site Branding

This is the sole Build 000050 pilot. It is trusted, repository-owned, and
loaded only through the core allowlist in `includes/first_party_extensions.php`.
Its manifest is descriptive; it cannot select executable code.

The adapter receives only declared branding presentation/settings,
validated-asset-reference, LICENSE, and MODIFICATIONS capabilities. It never
receives authentication or recovery secrets, private messages, moderation
evidence, hidden-media reasons, security events, unrestricted Tool Logs,
database handles, arbitrary filesystem access, or mutable runtime state.

Disabling removes its presentation subscriptions and restores standard
ChatSpace fallbacks. Namespaced settings are preserved. Storage cleanup is a
separate explicit core operation.

# Voice Runtime

VoiceRuntime owns voice workflow behavior for ChatSpace rooms.

Current ownership:

- voice state
- join/leave voice workflow
- mute/deafen state
- speaking detection state
- voice participant polling
- WebRTC peer coordination
- media signaling coordination
- audio device coordination
- voice diagnostics

VoiceRuntime does not own:

- voice note chat uploads
- chat media picker behavior
- avatar presentation
- webcam UI presentation
- game lifecycle
- room effects
- imported room behavior
- host modal presentation

Application code interacts with VoiceRuntime through the public runtime module.
Host UI, DOM shell callbacks, and API adapter wiring remain in `room.js`.

High-volume signaling, media lifecycle, and transport/RTP evidence is not owned
by VoiceRuntime. Build 000043 Part 2 routes that verification-only evidence to
the framework-core RuntimeDiagnostics contract when explicitly enabled.
VoiceMediaService retains peer and media ownership; diagnostics do not influence
negotiation, recovery, source, track, or presentation decisions.

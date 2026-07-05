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

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
- audio device coordination through VoiceDeviceService
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

Build 000043 Part 3 extracts the browser audio-device workflow into
VoiceDeviceService. That service owns device enumeration, explicit permission
refresh, selected output state, selected input constraints, sink routing, stale
enumeration generations, and the devicechange listener. VoiceMediaService
remains the public facade and injects only the required browser and host
callbacks.

Peer records, signal inbox and outcomes, canonical transceivers, negotiation,
local streams, speaking analysis, remote audio, webcam coordination, polling,
recovery, and coordinated cleanup remain in VoiceMediaService. These resources
must not be split before Build 000043 Part 4 defines lifecycle and resource
ownership. Part 5 remains reserved for the hardened shared API and any device
snapshot/generation redesign.

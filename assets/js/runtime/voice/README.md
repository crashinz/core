# Voice Runtime

VoiceRuntime owns voice workflow behavior for ChatSpace rooms.

Current ownership:

- voice state
- facade, participation, and microphone-acquisition lifecycle state through
  VoiceLifecycleService
- idempotent join/leave/destroy workflow
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

Build 000043 Part 4 establishes a multidimensional lifecycle rather than
conflating webcam and voice participation. VoiceLifecycleService owns facade,
participation, microphone intent/acquisition, and operation-generation state.
The room host retains independent local webcam capture intent and stream
ownership, consuming its own acquisition generation before it publishes a
capture to VoiceMediaService.

VoiceMediaService owns typed resource scopes for the local microphone,
speaking graph, peers, peer operation generations, signal drain, remote audio,
recovery timers, polling, and diagnostic probes. Peer replacement invalidates
the active map entry before listeners, media references, and the connection are
released. Pending and attached remote audio are bound to participant, peer
instance, peer generation, canonical transceiver, and receiver-track identity.

`join()` and `leave()` expose shared readiness promises while in progress.
Leave resolves after pending join work is neutralized and voice resources are
released. `destroy()` is synchronous, idempotent, and terminal because the Core
lifecycle hook is synchronous; stale browser promises may only release their
own result after destruction. `getResourceSnapshot()` and
`verifyResourceInvariants()` expose verification-safe ownership assertions.

Build 000043 Part 5 completes immutable, generation-numbered device snapshots
behind VoiceDeviceService. The service now owns selected input/output IDs,
permission state, selection fallback, sink capability, stale-completion
rejection, and snapshot publication. `room.js` renders the host modal from the
snapshot and forwards explicit selection UI events; it owns no second device
collection.

Part 5 closes Build 000043. Harness optimization, parallel equivalence,
performance telemetry, and cross-browser certification are deferred intact to
Build 000044 Part 10. Parts 1 through 9 establish the approved relationship,
formation, avatar-orientation, webcam-sizing, and dance feature foundations
before final Build 000044 verification and certification.

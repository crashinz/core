# Room Effects Runtime

**Build:** 000029  
**Owner:** RoomEffectsRuntime  
**Status:** Runtime Owned - Behavioral Verification Pending

RoomEffectsRuntime owns room-wide environmental effect lifecycle behavior.

## Ownership

RoomEffectsRuntime owns:

- room-wide environmental effect state
- room effect module loading
- room effect application
- room effect cleanup
- RoomRuntime room effect event reconciliation
- room effect diagnostics

RoomEffectsRuntime does not own:

- avatar-local effects
- aura presentation
- imported room layout or music behavior
- room effects modal presentation
- host API button wiring
- ChatRuntime, VoiceRuntime, GameRuntime, or ImportedRoomRuntime behavior

## Host Boundary

`room.js` remains the host shell for room effect menu clicks, modal rendering,
admin API adapters, and runtime configuration wiring. Runtime calls are routed
through `RoomEffectsService`, which owns the stateful effect lifecycle.

## Public Runtime Surface

- `RoomEffectsRuntime.effects.configure(context)`
- `RoomEffectsRuntime.effects.loadState()`
- `RoomEffectsRuntime.effects.apply(effectPayload, announce)`
- `RoomEffectsRuntime.effects.handleRoomEffect(payload)`
- `RoomEffectsRuntime.effects.cleanup()`
- `RoomEffectsRuntime.effects.effectByKey(key)`
- `RoomEffectsRuntime.effects.loadModule(effect)`
- `RoomEffectsRuntime.effects.getActiveEffect()`
- `RoomEffectsRuntime.getDiagnostics()`

## Behavioral Verification

Browser, PHP runtime, effect module runtime, deployment environment, and
multiplayer verification remain pending external validation and do not block
Engineering Completion for Build 000029.

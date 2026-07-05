# Game Runtime

GameRuntime owns embedded game lifecycle and game stage presentation behavior
for ChatSpace rooms.

Current ownership:

- game catalog metadata
- game list loading
- active game state
- open/close game lifecycle
- game iframe/stage coordination
- game stage visibility
- game refresh handling from RoomRuntime callbacks
- game diagnostics

GameRuntime does not own:

- game chat message sending
- game chat polling
- game chat typing state
- ChatRuntime message state
- RoomRuntime event classification
- RoomEffectsRuntime behavior
- ImportedRoomRuntime behavior
- VoiceRuntime behavior
- unrelated room UI behavior

Application code interacts with GameRuntime through the public runtime module.
Host UI events, DOM shell callbacks, API adapters, and runtime configuration
wiring remain in `room.js`.

# Imported Room Runtime

**Build:** 000031
**Owner:** ImportedRoomRuntime
**Status:** Runtime Owned - Behavioral Verification Pending

ImportedRoomRuntime owns imported room layout and imported room music behavior.

## Ownership

ImportedRoomRuntime owns:

- imported room layout rendering
- imported room image/content layout behavior
- imported background layer synchronization
- imported room music-player rendering
- imported website music-player integration
- imported room music-player compatibility logic
- domain-specific imported website player behavior, including
  inner-tranquillity.net compatibility logic
- imported website page-level player compatibility diagnostics
- imported room diagnostics

ImportedRoomRuntime does not own:

- ChatRuntime behavior
- AvatarRuntime behavior
- VoiceRuntime behavior
- GameRuntime behavior
- RoomEffectsRuntime behavior
- unrelated room UI behavior
- room background upload/edit API workflows

## Internal Components

- `ImportedRoomLayoutRenderer` owns imported room layout presentation.
- `ImportedRoomMusicService` owns imported room music-player behavior,
  modal lifecycle, inline embed presentation, and diagnostics.
- `ImportedRoomWebsitePlayerService` owns page-level imported website player
  compatibility, including inner-tranquillity.net MediaElement initialization
  and scoped page-level player presentation compatibility.

## Host Boundary

`room.js` remains the host shell for DOM element lookup, API/config data
assignment, room update callbacks, and runtime configuration wiring.

## Public Runtime Surface

- `ImportedRoomRuntime.layout.configure(context)`
- `ImportedRoomRuntime.layout.render(layout)`
- `ImportedRoomRuntime.layout.syncBackgroundLayer()`
- `ImportedRoomRuntime.music.configure(context)`
- `ImportedRoomRuntime.music.renderPlayer(playlist)`
- `ImportedRoomRuntime.music.openModal(track)`
- `ImportedRoomRuntime.music.closeModal()`
- `ImportedRoomRuntime.music.setMinimized(minimized)`
- `ImportedRoomRuntime.music.clampModal()`
- `ImportedRoomRuntime.websitePlayer.configure(context)`
- `ImportedRoomRuntime.websitePlayer.inlinePlayerHtml(track)`
- `ImportedRoomRuntime.websitePlayer.applyCompatibility(options)`
- `ImportedRoomRuntime.getDiagnostics()`

## Behavioral Verification

Browser, PHP runtime, imported website runtime, music-player runtime,
deployment environment, and multiplayer verification remain pending external
validation and do not block Engineering Completion for Build 000030.

# Room Runtime

RoomRuntime owns room-level runtime coordination that does not belong to
ChatRuntime, AvatarRuntime, or another specialized runtime.

Current ownership:

- non-chat room poll event classification
- non-chat community poll event classification
- routing non-chat poll events to owning runtimes or host callbacks

RoomRuntime does not own:

- chat message/event routing
- avatar behavior
- voice behavior
- game lifecycle
- room effects behavior
- imported room behavior
- host modal presentation
- API adapters

Application code interacts with RoomRuntime through the public runtime module.
Internal components remain implementation details.

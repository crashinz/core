/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      core-types.js
 *
 * Layer:
 *      0 - Constants & Metadata
 *
 * Purpose:
 *      Centralized architectural identifiers and immutable metadata used
 *      throughout the Chat Runtime Framework.
 *
 *      This module intentionally contains only architectural constants,
 *      identifiers, metadata, and enumerations.
 *
 *      No runtime behavior, helper functions, validation logic, or classes
 *      belong in this file.
 *
 * Build:
 *      000011
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000011
 * • Permanent Foundation Layer implementation.
 ******************************************************************************/

/**
 * @file core-types.js
 *
 * Centralized architectural identifiers for the Chat Runtime Framework.
 *
 * Every exported identifier group is frozen individually to preserve
 * framework integrity while allowing explicit imports and optimal
 * tree-shaking.
 *
 * Engineering Standards
 * ---------------------
 * • No runtime logic.
 * • No helper functions.
 * • No classes.
 * • No validation.
 * • No magic strings outside this module.
 * • One exported identifier group per architectural concern.
 */

//--------------------------------------------------
// Framework
//--------------------------------------------------

/**
 * Framework metadata.
 *
 * @readonly
 */
export const FRAMEWORK = Object.freeze({

    DESCRIPTION:
        "Modular runtime extending ChatSpace while keeping new functionality independent, maintainable, and easy to merge.",

    NAME:
        "Chat Runtime Framework for ChatSpace",

    REPOSITORY:
        "ChatSpace",

    RUNTIME:
        "Chat.Core",

    SHORT_NAME:
        "Chat Runtime Framework"

});

//--------------------------------------------------
// Build
//--------------------------------------------------

/**
 * Current framework build information.
 *
 * @readonly
 */
export const BUILD = Object.freeze({

    DISPLAY:
        "000011",

    GOAL:
        "Foundation Layer",

    NUMBER:
        11

});

//--------------------------------------------------
// Layers
//--------------------------------------------------

/**
 * Framework layer identifiers.
 *
 * Lower layers may never depend upon higher layers.
 *
 * Layer hierarchy:
 *
 * 0  Constants & Metadata
 * 1  Foundation
 * 2  Runtime
 * 3  Services
 * 4  Integration
 * 5  Engines
 * 6  Developer
 *
 * @readonly
 */
export const LAYERS = Object.freeze({

    CONSTANTS:
        0,

    DEVELOPER:
        6,

    ENGINES:
        5,

    FOUNDATION:
        1,

    INTEGRATION:
        4,

    RUNTIME:
        2,

    SERVICES:
        3

});

//--------------------------------------------------
// Lifecycle
//--------------------------------------------------

/**
 * Core module lifecycle states.
 *
 * Lifecycle order:
 *
 * UNCONFIGURED
 * ↓
 * CONFIGURED
 * ↓
 * INITIALIZED
 * ↓
 * STARTED
 * ↓
 * STOPPED
 * ↓
 * DISPOSED
 *
 * These values intentionally remain in lifecycle order
 * rather than alphabetical order.
 *
 * @readonly
 */
export const LIFECYCLE = Object.freeze({

    UNCONFIGURED:
        "unconfigured",

    CONFIGURED:
        "configured",

    INITIALIZED:
        "initialized",

    STARTED:
        "started",

    STOPPED:
        "stopped",

    DISPOSED:
        "disposed"

});

//--------------------------------------------------
// Module States
//--------------------------------------------------

/**
 * Runtime module states.
 *
 * Module state is intentionally separate from lifecycle.
 *
 * @readonly
 */
export const MODULE_STATES = Object.freeze({

    ACTIVE:
        "active",

    DISABLED:
        "disabled",

    FAILED:
        "failed",

    LOADED:
        "loaded",

    REGISTERED:
        "registered"

});

//--------------------------------------------------
// Services
//--------------------------------------------------

/**
 * Framework service identifiers.
 *
 * Services are registered with and requested from
 * Chat.Core. Modules never instantiate services
 * directly.
 *
 * Service identifiers use Symbol values to guarantee
 * uniqueness and prevent collisions.
 *
 * @readonly
 */
export const SERVICES = Object.freeze({

    DIAGNOSTICS:
        Symbol("diagnostics"),

    EVENT_BUS:
        Symbol("event-bus"),

    LOGGER:
        Symbol("logger"),

    MODULE_MANAGER:
        Symbol("module-manager"),

    ROLLBACK_MANAGER:
        Symbol("rollback-manager")

});

//--------------------------------------------------
// Modules
//--------------------------------------------------

/**
 * Framework module identifiers.
 *
 * These identifiers uniquely identify framework
 * modules regardless of their implementation file.
 *
 * These values intentionally remain strings because
 * they appear in diagnostics, manifests, logs, and
 * developer tooling.
 *
 * @readonly
 */
export const MODULES = Object.freeze({

    AVATAR_ENGINE:
        "avatar-engine",

    CHAT_BOOTSTRAP:
        "chat-bootstrap",

    CORE:
        "core",

    DEVELOPER_DASHBOARD:
        "developer-dashboard",

    DIAGNOSTICS:
        "diagnostics",

    EVENT_BUS:
        "event-bus",

    LOGGER:
        "logger",

    MODULE_MANAGER:
        "module-manager",

    ROLLBACK_MANAGER:
        "rollback-manager",

    ROOM_INTEGRATION:
        "room-integration"

});

//--------------------------------------------------
// Events
//--------------------------------------------------

/**
 * Framework event identifiers.
 *
 * Events are emitted through the Event Bus.
 *
 * Event identifiers intentionally remain readable
 * strings to simplify diagnostics and developer
 * tooling.
 *
 * Event names use namespaces to reduce collisions.
 *
 * @readonly
 */
export const EVENTS = Object.freeze({

    //--------------------------------------------------
    // Diagnostics
    //--------------------------------------------------

    DIAGNOSTICS_UPDATED:
        "diagnostics:updated",
	
    SELF_TEST_COMPLETED:
        "diagnostics:self-test-completed",

    //--------------------------------------------------
    // Modules
    //--------------------------------------------------

    MODULE_CONFIGURED:
        "module:configured",

    MODULE_DISPOSED:
        "module:disposed",

    MODULE_FAILED:
        "module:failed",

    MODULE_INITIALIZED:
        "module:initialized",

    MODULE_REGISTERED:
        "module:registered",

    MODULE_STARTED:
        "module:started",

    MODULE_STOPPED:
        "module:stopped",

    //--------------------------------------------------
    // Runtime
    //--------------------------------------------------

    RUNTIME_INITIALIZING:
        "core:runtime-initializing",

    RUNTIME_READY:
        "core:runtime-ready",

    RUNTIME_STOPPED:
        "core:runtime-stopped",

    RUNTIME_STOPPING:
        "core:runtime-stopping",

    //--------------------------------------------------
    // Services
    //--------------------------------------------------

    SERVICE_REGISTERED:
        "service:registered",

    SERVICE_REMOVED:
        "service:removed"

});

//--------------------------------------------------
// Error Codes/Identifiers
//--------------------------------------------------

/**
 * Framework error identifiers.
 *
 * Error codes provide stable, machine-readable
 * identifiers for framework exceptions.
 *
 * Error classes should reference these identifiers
 * instead of embedding string literals.
 *
 * @readonly
 */
export const ERROR_CODES = Object.freeze({

    CONFIGURATION_INVALID:
        "CONFIGURATION_INVALID",

    DEPENDENCY_MISSING:
        "DEPENDENCY_MISSING",

    FRAMEWORK_INTERNAL_ERROR:
        "FRAMEWORK_INTERNAL_ERROR",

    LIFECYCLE_INVALID_STATE:
        "LIFECYCLE_INVALID_STATE",

    MODULE_ALREADY_REGISTERED:
        "MODULE_ALREADY_REGISTERED",

    MODULE_NOT_FOUND:
        "MODULE_NOT_FOUND",

    MODULE_START_FAILED:
        "MODULE_START_FAILED",

    MODULE_REGISTRATION_FAILED:
        "MODULE_REGISTRATION_FAILED",

    SERVICE_ALREADY_REGISTERED:
        "SERVICE_ALREADY_REGISTERED",

    SERVICE_NOT_FOUND:
        "SERVICE_NOT_FOUND",

    VALIDATION_FAILED:
        "VALIDATION_FAILED"

});

//--------------------------------------------------
// Runtime States
//--------------------------------------------------

/**
 * Permanent framework runtime states.
 *
 * The framework runtime always exists in exactly one state.
 *
 * State transitions are coordinated exclusively by Chat.Core.
 *
 * @readonly
 */
export const RUNTIME_STATES = Object.freeze({

    CREATED: "created",

    INITIALIZED: "initialized",

    STARTED: "started",

    STOPPED: "stopped",

    DESTROYED: "destroyed"

});

//--------------------------------------------------
// Log Levels
//--------------------------------------------------

/**
 * Framework logger severity levels.
 *
 * These values intentionally remain ordered from
 * lowest severity to highest severity rather than
 * alphabetically.
 *
 * @readonly
 */
export const LOG_LEVELS = Object.freeze({

    TRACE:
        "trace",

    DEBUG:
        "debug",

    INFO:
        "info",

    WARN:
        "warn",

    ERROR:
        "error",

    FATAL:
        "fatal"

});

//--------------------------------------------------
// Capabilities
//--------------------------------------------------

/**
 * Framework capability identifiers.
 *
 * Capabilities describe features supported by the
 * framework independent of which module implements
 * them.
 *
 * @readonly
 */
export const CAPABILITIES = Object.freeze({

    AVATAR_ATTACHMENT:
        "avatar-attachment",

    DEVELOPER_TOOLS:
        "developer-tools",

    DIAGNOSTICS:
        "diagnostics",

    EVENTS:
        "events",

    LOGGING:
        "logging",

    MODULES:
        "modules",

    PLUGINS:
        "plugins",

    RENDERERS:
        "renderers",

    ROLLBACK:
        "rollback"

});

//--------------------------------------------------
// Diagnostics
//--------------------------------------------------

/**
 * Diagnostic status identifiers.
 *
 * @readonly
 */
export const DIAGNOSTICS = Object.freeze({

    ERROR:
        "error",

    HEALTH:
        "health",

    PASS:
        "pass",

    UNKNOWN:
        "unknown",

    WARNING:
        "warning"

});

//--------------------------------------------------
// Metadata
//--------------------------------------------------

/**
 * Canonical metadata keys exposed by Chat.Core.
 *
 * Metadata is read-only outside the framework.
 *
 * @readonly
 */
export const METADATA = Object.freeze({

    BUILD:
        "build",

    BUILD_DATE:
        "buildDate",

    FRAMEWORK_FINGERPRINT:
        "frameworkFingerprint",

    INITIALIZED:
        "initialized",

    MODULE_COUNT:
        "moduleCount",

    NAME:
        "name",

    SERVICE_COUNT:
        "serviceCount",

    UPTIME:
        "uptime"

});

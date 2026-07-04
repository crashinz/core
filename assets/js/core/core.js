/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      core.js
 *
 * Layer:
 *      2 - Runtime
 *
 * Purpose:
 *      Defines the permanent runtime controller for the Chat Runtime
 *      Framework.
 *
 *      Core coordinates framework modules, services, runtime state, and
 *      lifecycle execution.
 *
 * Build:
 *      000011
 ******************************************************************************/

import {
    BUILD,
    RUNTIME_STATES
} from "./core-types.js";

import {
    LifecycleError,
    ValidationError
} from "./core-errors.js";

import {
    CoreModule
} from "./core-module.js";

//--------------------------------------------------
// Core Runtime
//--------------------------------------------------

export class Core {

    //--------------------------------------------------
    // Private Fields
    //--------------------------------------------------

    #build;
    #modules;
    #services;
    #events;
    #state;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    constructor() {

        this.#build = BUILD.NUMBER;

        this.#modules = new Map();

        this.#services = new Map();

        this.#events = new Map();

        this.#state = RUNTIME_STATES.CREATED;
    }

    //--------------------------------------------------
    // Public API - Getters
    //--------------------------------------------------

    get build() {

        return this.#build;
    }

    get state() {

        return this.#state;
    }

    //--------------------------------------------------
    // Public API - Event System
    //--------------------------------------------------

    /**
     * Emits an event to all registered listeners.
     */
    emit(event, payload) {

        const listeners = this.#events.get(event);

        if (!listeners) return;

        for (const handler of listeners) {

            try {

                handler(payload);

            } catch (error) {

                throw new LifecycleError({

                    message: `Error in event handler for '${event}'.`,

                    cause: error

                });
            }
        }
    }

    /**
     * Registers an event listener.
     */
    on(event, handler) {

        let listeners = this.#events.get(event);

        if (!listeners) {

            listeners = new Set();

            this.#events.set(event, listeners);
        }

        listeners.add(handler);
    }

    /**
     * Removes an event listener.
     */
    off(event, handler) {

        const listeners = this.#events.get(event);

        if (!listeners) return;

        listeners.delete(handler);

        if (listeners.size === 0) {

            this.#events.delete(event);
        }
    }

    //--------------------------------------------------
    // Public API - Module System
    //--------------------------------------------------

    registerModule(module) {

        if (this.#state !== RUNTIME_STATES.CREATED) {

            throw new LifecycleError({

                message: "Modules may only be registered before runtime initialization."
            });
        }

        if (!(module instanceof CoreModule)) {

            throw new ValidationError({

                message: "Module must inherit from CoreModule."
            });
        }

        const { id } = module;

        if (this.#modules.has(id)) {

            throw new LifecycleError({

                message: `Module '${id}' is already registered.`
            });
        }

        this.#modules.set(id, module);

        return module;
    }

    hasModule(id) {

        return this.#modules.has(id);
    }

    getModule(id) {

        return this.#modules.get(id) ?? null;
    }

    //--------------------------------------------------
    // Public API - Service System
    //--------------------------------------------------

    registerService(id, service) {

        if (this.#services.has(id)) {

            throw new LifecycleError({

                message: `Service '${id}' is already registered.`
            });
        }

        this.#services.set(id, service);
    }

    getService(id) {

        return this.#services.get(id) ?? null;
    }

    //--------------------------------------------------
    // Runtime Lifecycle
    //--------------------------------------------------

    initialize() {

        if (this.#state !== RUNTIME_STATES.CREATED) {

            throw new LifecycleError({

                message: "Runtime has already been initialized."
            });
        }

        this.#invokeLifecycle(
            "initialize",
            module => module.onInitialize()
        );

        this.#state = RUNTIME_STATES.INITIALIZED;
    }

    start() {

        if (this.#state !== RUNTIME_STATES.INITIALIZED) {

            throw new LifecycleError({

                message: "Runtime must be initialized before starting."
            });
        }

        this.#invokeLifecycle(
            "start",
            module => module.onStart()
        );

        this.#state = RUNTIME_STATES.STARTED;
    }

    stop() {

        if (this.#state !== RUNTIME_STATES.STARTED) {

            throw new LifecycleError({

                message: "Runtime is not running."
            });
        }

        this.#invokeLifecycle(
            "stop",
            module => module.onStop()
        );

        this.#state = RUNTIME_STATES.STOPPED;
    }

    destroy() {

        if (this.#state !== RUNTIME_STATES.STOPPED) {

            throw new LifecycleError({

                message: "Runtime must be stopped before destruction."
            });
        }

        this.#invokeLifecycle(
            "destroy",
            module => module.onDestroy()
        );

        this.#modules.clear();
        this.#services.clear();

        this.#state = RUNTIME_STATES.DESTROYED;
    }

    //--------------------------------------------------
    // Private Helpers
    //--------------------------------------------------

    #invokeLifecycle(phase, callback) {

        for (const module of this.#modules.values()) {

            try {

                callback(module);

            } catch (error) {

                throw new LifecycleError({

                    message: `Module '${module.id}' failed during runtime ${phase}.`,

                    cause: error

                });
            }
        }
    }
}
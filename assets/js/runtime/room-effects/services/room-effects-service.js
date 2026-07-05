/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      room-effects-service.js
 *
 * Layer:
 *      Runtime Service
 *
 * Owner:
 *      Room Effects Runtime
 *
 * Purpose:
 *      Owns room-wide environmental effect state, module loading, application,
 *      cleanup, RoomRuntime event reconciliation, and diagnostics.
 *
 * Build:
 *      000029
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000029
 * - Introduced RoomEffectsService.
 * - Transferred room-wide environmental effect ownership from room.js.
 ******************************************************************************/

/**
 * @file room-effects-service.js
 *
 * Defines the Room Effects Service.
 */

//
// No imports required.
//

//--------------------------------------------------
// Room Effects Service
//--------------------------------------------------

/**
 * Owns room-wide environmental effect lifecycle behavior.
 */
export class RoomEffectsService {

    //--------------------------------------------------
    // Private Fields
    //--------------------------------------------------

    #runtime;

    #context = null;

    #loadedModules = new Map();

    #activeController = null;

    #activeEffect = null;

    #lastAppliedKey = null;

    #applyCount = 0;

    #cleanupCount = 0;

    #lastError = null;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Room Effects Service.
     *
     * @param {RoomEffectsRuntime} runtime
     *        Owning Room Effects Runtime.
     */
    constructor(runtime) {

        this.#runtime = runtime;

    }

    //--------------------------------------------------
    // Public Lifecycle
    //--------------------------------------------------

    /**
     * Initializes the service.
     */
    initialize() {

    }

    /**
     * Releases room effect state and presentation.
     */
    destroy() {

        this.cleanup();
        this.#loadedModules.clear();
        this.#context = null;

    }

    //--------------------------------------------------
    // Public Getters
    //--------------------------------------------------

    /**
     * Returns the owning Room Effects Runtime.
     *
     * @returns {RoomEffectsRuntime}
     */
    get runtime() {

        return this.#runtime;

    }

    /**
     * Returns the active room effect payload.
     *
     * @returns {Object|null}
     */
    getActiveEffect() {

        return this.#activeEffect;

    }

    //--------------------------------------------------
    // Public Configuration
    //--------------------------------------------------

    /**
     * Configures host callbacks, shell adapters, and DOM dependencies.
     *
     * @param {Object} context
     */
    configure(context = {}) {

        this.#context = context;

    }

    //--------------------------------------------------
    // Public Catalog
    //--------------------------------------------------

    /**
     * Returns a configured room effect by key.
     *
     * @param {string} key
     *
     * @returns {Object|null}
     */
    effectByKey(key) {

        return (this.#config().roomEffects || []).find(effect =>
            effect.key === key
        ) || null;

    }

    /**
     * Loads and returns a room effect module.
     *
     * @param {Object} effect
     *
     * @returns {Promise<Object>}
     */
    loadModule(effect) {

        return this.#loadModule(effect);

    }

    //--------------------------------------------------
    // Public Workflow
    //--------------------------------------------------

    /**
     * Loads available room effects and the current active effect.
     *
     * @returns {Promise<Object>}
     */
    async loadState() {

        const data =
            await this.#fetchEffectsState();

        if (data?.error) {

            throw new Error(
                data.error
            );

        }

        this.#context?.setRoomEffectsState?.(
            data?.effects || [],
            data?.current || null
        );

        return data;

    }

    /**
     * Applies or clears a room-wide environmental effect.
     *
     * @param {Object|null} effectPayload
     * @param {boolean} announce
     *
     * @returns {Promise<void>}
     */
    async apply(effectPayload, announce = false) {

        this.cleanup();

        this.#activeEffect =
            effectPayload?.active ? effectPayload : null;

        this.#context?.setActiveRoomEffect?.(
            this.#activeEffect
        );

        if (!this.#activeEffect) {

            this.#announceStopped(
                effectPayload,
                announce
            );

            return;

        }

        const effect =
            Object.assign(
                {},
                this.effectByKey(this.#activeEffect.effect_key) || {},
                this.#activeEffect
            );

        try {

            const module =
                await this.#loadModule(
                    effect
                );

            if (!this.#activeEffect || this.#activeEffect.effect_key !== effect.effect_key) {

                return;

            }

            this.#document()?.body?.classList.add(
                "has-room-effect"
            );

            const context =
                this.#createEffectContext(
                    effect
                );

            const controller =
                module.mount(
                    context
                ) || {};

            this.#activeController = {

                destroy() {

                    controller.destroy?.();
                    context.cleanup?.();

                }

            };

            this.#lastAppliedKey =
                effect.effect_key;

            this.#applyCount += 1;
            this.#lastError = null;

            if (announce) {

                const by =
                    effect.changed_by_name || effect.started_by_name || "Someone";

                this.#context?.addSystemMessage?.(
                    `${by} started ${effect.label}.`
                );

            }

        } catch (error) {

            this.cleanup();

            this.#lastError =
                error?.message || "Room effect could not start.";

            this.#context?.addSystemMessage?.(
                this.#lastError
            );

        }

    }

    /**
     * Reconciles a room effect event routed by RoomRuntime.
     *
     * @param {Object} payload
     *
     * @returns {Promise<void>}
     */
    async handleRoomEffect(payload) {

        await this.apply(
            payload,
            true
        );

        this.#context?.renderRoomEffectsModal?.();

    }

    /**
     * Removes active room effect presentation.
     */
    cleanup() {

        this.#activeController?.destroy?.();
        this.#activeController = null;
        this.#activeEffect = null;
        this.#cleanupCount += 1;

        this.#document()?.body?.classList.remove(
            "has-room-effect"
        );

        const stage =
            this.#roomStage();

        stage?.querySelectorAll(".room-effect-layer").forEach(layer =>
            layer.remove()
        );

        if (stage) {

            [...stage.classList].forEach(className => {

                if (className.startsWith("effect-")) {

                    stage.classList.remove(
                        className
                    );

                }

            });

        }

    }

    //--------------------------------------------------
    // Public Diagnostics
    //--------------------------------------------------

    /**
     * Returns room effect diagnostics.
     *
     * @returns {Object}
     */
    getDiagnostics() {

        return Object.freeze({

            owner:
                "RoomEffectsRuntime",

            build:
                "000029",

            configured:
                Boolean(this.#context),

            active:
                Boolean(this.#activeEffect),

            activeEffectKey:
                this.#activeEffect?.effect_key || null,

            loadedModuleCount:
                this.#loadedModules.size,

            lastAppliedKey:
                this.#lastAppliedKey,

            applyCount:
                this.#applyCount,

            cleanupCount:
                this.#cleanupCount,

            lastError:
                this.#lastError

        });

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    #config() {

        return this.#context?.getConfig?.() || {};

    }

    #document() {

        return this.#context?.document || globalThis.document || null;

    }

    #window() {

        return this.#context?.window || globalThis.window || null;

    }

    #roomStage() {

        return this.#context?.getRoomStage?.() || this.#context?.roomStage || null;

    }

    #participants() {

        return this.#context?.getParticipants?.() || new Map();

    }

    #css() {

        return this.#context?.CSS || this.#window()?.CSS || null;

    }

    #appUrl(path) {

        return this.#context?.appUrl?.(
            path
        ) || path;

    }

    #mediaUrl(path) {

        return this.#context?.mediaUrl?.(
            path
        ) || this.#appUrl(
            path
        );

    }

    #cacheBust(src) {

        return this.#context?.cacheBust?.(
            src
        ) || src;

    }

    #fetchEffectsState() {

        const query =
            new URLSearchParams({
                action: "effects",
                session_id: this.#config().sessionId,
                join_token: this.#config().myJoinToken
            });

        return this.#context?.fetchEffectsState?.(
            query
        ) ?? Promise.resolve({
            effects: [],
            current: null
        });

    }

    async #loadModule(effect) {

        if (!effect?.script) {

            throw new Error(
                "Room effect script missing."
            );

        }

        const src =
            this.#appUrl(
                effect.script
            );

        if (this.#loadedModules.has(src)) {

            return this.#loadedModules.get(src);

        }

        await new Promise((resolve, reject) => {

            const documentRef =
                this.#document();

            const cssRef =
                this.#css();

            const escapedSrc =
                cssRef?.escape ? cssRef.escape(src) : String(src).replace(/"/g, '\\"');

            const existing =
                documentRef?.querySelector?.(
                    `script[data-room-effect-src="${escapedSrc}"]`
                );

            if (existing) {

                if (existing.dataset.loaded === "1") {

                    resolve();

                } else {

                    existing.addEventListener(
                        "load",
                        resolve,
                        { once: true }
                    );

                }

                return;

            }

            const script =
                documentRef?.createElement?.(
                    "script"
                );

            if (!script) {

                reject(
                    new Error("Room effect script could not be created.")
                );

                return;

            }

            script.src =
                this.#cacheBust(
                    src
                );

            script.async = true;
            script.dataset.roomEffectSrc = src;

            script.addEventListener(
                "load",
                () => {

                    script.dataset.loaded = "1";
                    resolve();

                },
                { once: true }
            );

            script.addEventListener(
                "error",
                () => reject(
                    new Error(`Could not load ${effect.label || effect.key}.`)
                ),
                { once: true }
            );

            documentRef.head.appendChild(
                script
            );

        });

        const module =
            this.#window()?.ChatSpaceRoomEffects?.[effect.key];

        if (!module) {

            throw new Error(
                `${effect.label || effect.key} did not register itself.`
            );

        }

        this.#loadedModules.set(
            src,
            module
        );

        return module;

    }

    #createEffectContext(effect) {

        const disposers =
            [];

        const stage =
            this.#roomStage();

        const windowRef =
            this.#window();

        const participants =
            this.#participants();

        return {

            appUrl:
                path => this.#appUrl(path),

            mediaUrl:
                path => this.#mediaUrl(path),

            roomStage:
                stage,

            participants:
                participants,

            getParticipant:
                id => participants.get(Number(id)),

            getAvatars:
                () => [...participants.values()]
                    .filter(person => person.avatarEl || person.webcamVideoEl)
                    .map(person => ({
                        participant: person,
                        element: person.webcamVideoEl || person.avatarEl
                    })),

            addStageListener:
                (type, handler, options) => {

                    stage?.addEventListener(
                        type,
                        handler,
                        options
                    );

                    disposers.push(() =>
                        stage?.removeEventListener(
                            type,
                            handler,
                            options
                        )
                    );

                },

            addWindowListener:
                (type, handler, options) => {

                    windowRef?.addEventListener(
                        type,
                        handler,
                        options
                    );

                    disposers.push(() =>
                        windowRef?.removeEventListener(
                            type,
                            handler,
                            options
                        )
                    );

                },

            onSystemMessage:
                text => this.#context?.addSystemMessage?.(text),

            effect:
                effect,

            cleanup:
                () => disposers.splice(0).forEach(dispose => dispose())

        };

    }

    #announceStopped(effectPayload, announce) {

        if (!announce) {

            return;

        }

        if (effectPayload?.expired) {

            this.#context?.addSystemMessage?.(
                `${effectPayload?.label || "Room effect"} ended.`
            );

            return;

        }

        this.#context?.addSystemMessage?.(
            `${effectPayload?.stopped_by_name || "Someone"} stopped ${effectPayload?.label || "Room Effect"}.`
        );

    }

}

export default RoomEffectsService;

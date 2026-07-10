/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      avatar-aura-service.js
 *
 * Layer:
 *      Runtime Service
 *
 * Owner:
 *      Avatar Runtime
 *
 * Purpose:
 *      Owns avatar aura catalog, module loading, current aura state, aura API
 *      workflow, and participant aura application coordination.
 *
 * Build:
 *      000033
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000033
 * - Introduced AvatarAuraService.
 * - Transferred avatar aura workflow ownership from room.js.
 ******************************************************************************/

/**
 * @file avatar-aura-service.js
 *
 * Defines the Avatar Aura Service.
 */

//--------------------------------------------------
// Avatar Aura Service
//--------------------------------------------------

/**
 * Owns avatar aura workflow behavior.
 */
export class AvatarAuraService {

    //--------------------------------------------------
    // Private Fields
    //--------------------------------------------------

    #runtime;

    #context = null;

    #catalog = [];

    #modules = new Map();

    #loadChain = Promise.resolve();

    #selectedKey = "";

    #catalogLoaded = false;

    #loadCount = 0;

    #applyCount = 0;

    #lastError = "";

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Avatar Aura Service.
     *
     * @param {AvatarRuntime} runtime
     *        Owning Avatar Runtime.
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
     * Releases aura workflow state.
     */
    destroy() {

        this.#catalog = [];
        this.#modules.clear();
        this.#loadChain = Promise.resolve();
        this.#selectedKey = "";
        this.#catalogLoaded = false;
        this.#context = null;

    }

    //--------------------------------------------------
    // Public Getters
    //--------------------------------------------------

    /**
     * Returns the owning Avatar Runtime.
     *
     * @returns {AvatarRuntime}
     */
    get runtime() {

        return this.#runtime;

    }

    /**
     * Returns the currently selected aura key.
     *
     * @returns {string}
     */
    selectedKey() {

        return this.#selectedKey;

    }

    /**
     * Returns the loaded aura catalog.
     *
     * @returns {Object[]}
     */
    catalog() {

        return this.#catalog.slice();

    }

    //--------------------------------------------------
    // Public Configuration
    //--------------------------------------------------

    /**
     * Configures host callbacks and browser dependencies.
     *
     * @param {Object} context
     */
    configure(context = {}) {

        this.#context = context;

    }

    //--------------------------------------------------
    // Public Catalog API
    //--------------------------------------------------

    /**
     * Returns an aura definition by key.
     *
     * @param {string} key
     *
     * @returns {Object|null}
     */
    auraByKey(key) {

        return this.#catalog.find(aura => aura.key === key) || null;

    }

    /**
     * Loads the aura catalog.
     *
     * @returns {Promise<Object[]>}
     */
    async loadCatalog() {

        if (this.#catalogLoaded) {
            return this.catalog();
        }

        const config =
            this.#config();

        const query =
            new URLSearchParams({
                session_id: config.sessionId,
                join_token: config.myJoinToken
            });

        const data =
            await this.#context?.fetchJson?.(
                `/api/auras.php?${query}`
            );

        if (data?.error) {
            throw new Error(data.error);
        }

        this.#catalog =
            Array.isArray(data?.auras)
                ? data.auras
                : [];

        this.#catalogLoaded =
            true;

        return this.catalog();

    }

    /**
     * Sets the active selected aura key.
     *
     * @param {string} key
     *
     * @returns {string}
     */
    setSelectedKey(key) {

        this.#selectedKey =
            key || "";

        return this.#selectedKey;

    }

    /**
     * Loads catalog data and selects the participant's current aura.
     *
     * @param {Object} participant
     *
     * @returns {Promise<Object>}
     */
    async prepareSelection(participant) {

        const catalog =
            await this.loadCatalog();

        this.setSelectedKey(
            participant?.aura_effect || ""
        );

        return Object.freeze({

            catalog,

            selectedKey:
                this.#selectedKey

        });

    }

    //--------------------------------------------------
    // Public Module API
    //--------------------------------------------------

    /**
     * Loads an aura module.
     *
     * @param {Object} aura
     *
     * @returns {Promise<Object>}
     */
    async loadModule(aura) {

        if (!aura?.script) {
            throw new Error("Aura script missing.");
        }

        const src =
            this.#appUrl(
                aura.script
            );

        if (this.#modules.has(src)) {
            return this.#modules.get(src);
        }

        const load =
            this.#loadChain
                .catch(() => {})
                .then(() => this.#loadScriptModule(src, aura));

        this.#loadChain =
            load.catch(() => {});

        return load;

    }

    //--------------------------------------------------
    // Public Presentation Coordination
    //--------------------------------------------------

    /**
     * Cleans an aura presentation layer.
     *
     * @param {HTMLElement} layer
     *
     * @returns {HTMLElement|null}
     */
    cleanupLayer(layer) {

        return this.#runtime.renderer?.cleanupAuraLayer(
            layer,
            {
                document:
                    this.#document()
            }
        ) || null;

    }

    /**
     * Applies an aura key to a presentation layer.
     *
     * @param {HTMLElement} layer
     * @param {string} key
     *
     * @returns {Promise<void>}
     */
    async applyToLayer(layer, key) {

        await this.#runtime.renderer?.applyAuraToLayer(
            layer,
            key,
            this.#renderOptions()
        );

        this.#applyCount += 1;

    }

    /**
     * Applies a participant's current aura.
     *
     * @param {Object} participant
     *
     * @returns {Promise<void>}
     */
    async applyParticipantAura(participant) {

        if (!participant?.auraEl) return;

        await this.#runtime.renderer?.applyParticipantAura(
            participant,
            this.#renderOptions()
        );

        this.#applyCount += 1;

    }

    //--------------------------------------------------
    // Public Workflow API
    //--------------------------------------------------

    /**
     * Persists the selected aura for the current user.
     *
     * @returns {Promise<string>}
     */
    async setCurrentAura() {

        const config =
            this.#config();

        const auraKey =
            this.#selectedKey || "";

        await this.#context?.apiPost?.(
            "/api/auras.php",
            {
                session_id:
                    config.sessionId,

                join_token:
                    config.myJoinToken,

                aura_key:
                    auraKey
            }
        );

        const participants =
            this.#participants();

        participants.forEach(person => {

            if (Number(person.user_id) !== Number(config.myUserId)) return;

            person.aura_effect =
                auraKey || null;

            this.applyParticipantAura(person)
                .catch(error => this.#handleError(error));

        });

        return auraKey;

    }

    //--------------------------------------------------
    // Public Diagnostics
    //--------------------------------------------------

    /**
     * Returns aura workflow diagnostics.
     *
     * @returns {Object}
     */
    getDiagnostics() {

        return Object.freeze({

            owner:
                "AvatarRuntime",

            build:
                "000033",

            configured:
                Boolean(this.#context),

            catalogLoaded:
                this.#catalogLoaded,

            catalogCount:
                this.#catalog.length,

            moduleCount:
                this.#modules.size,

            selectedKey:
                this.#selectedKey,

            loadCount:
                this.#loadCount,

            applyCount:
                this.#applyCount,

            lastError:
                this.#lastError

        });

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    #renderOptions() {

        return {

            document:
                this.#document(),

            auraByKey:
                key => this.auraByKey(key),

            loadAuraModule:
                aura => this.loadModule(aura),

            onError:
                error => this.#handleError(error)

        };

    }

    #loadScriptModule(src, aura) {

        return new Promise((resolve, reject) => {

            const windowRef =
                this.#window();

            const documentRef =
                this.#document();

            const previousModule =
                windowRef.module;

            const previousExports =
                windowRef.exports;

            const moduleShim =
                { exports: {} };

            const restore = () => {

                if (previousModule === undefined) {
                    delete windowRef.module;
                } else {
                    windowRef.module = previousModule;
                }

                if (previousExports === undefined) {
                    delete windowRef.exports;
                } else {
                    windowRef.exports = previousExports;
                }

            };

            const script =
                documentRef.createElement("script");

            windowRef.module =
                moduleShim;

            windowRef.exports =
                moduleShim.exports;

            script.src =
                this.#cacheBust(src);

            script.async =
                false;

            script.dataset.auraSrc =
                src;

            script.addEventListener(
                "load",
                () => {

                    const exported =
                        moduleShim.exports;

                    restore();
                    script.remove();

                    if (!exported?.render) {
                        reject(
                            new Error(`${aura.label || aura.key} did not expose an aura renderer.`)
                        );
                        return;
                    }

                    this.#modules.set(
                        src,
                        exported
                    );

                    this.#loadCount += 1;

                    resolve(
                        exported
                    );

                },
                { once: true }
            );

            script.addEventListener(
                "error",
                () => {

                    restore();
                    script.remove();

                    reject(
                        new Error(`Could not load ${aura.label || aura.key}.`)
                    );

                },
                { once: true }
            );

            documentRef.head.appendChild(
                script
            );

        });

    }

    #handleError(error) {

        this.#lastError =
            error?.message || String(error || "");

        this.#context?.onError?.(
            error
        );

    }

    #config() {

        return this.#context?.getConfig?.() || {};

    }

    #participants() {

        return this.#context?.getParticipants?.() || new Map();

    }

    #document() {

        return this.#context?.document || globalThis.document || null;

    }

    #window() {

        return this.#context?.window || globalThis.window || null;

    }

    #appUrl(path) {

        return this.#context?.appUrl?.(path) || path;

    }

    #cacheBust(url) {

        return this.#context?.cacheBust?.(url) || url;

    }

}

export default AvatarAuraService;

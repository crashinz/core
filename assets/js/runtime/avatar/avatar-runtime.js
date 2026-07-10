/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      avatar-runtime.js
 *
 * Layer:
 *      Runtime
 *
 * Owner:
 *      Avatar Runtime
 *
 * Purpose:
 *      Owns the Avatar Runtime.
 *
 *      AvatarRuntime coordinates avatar-specific runtime components while
 *      participating in the framework module lifecycle.
 *
 *      AvatarRuntime owns runtime component construction and lifetime but
 *      does not directly implement avatar behavior.
 *
 * Build:
 *      000033
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000012
 * • Introduced Avatar Runtime module.
 * • Established runtime component ownership.
 * • Established runtime lifecycle participation.
 * • Introduced Relationship, Order, Layout, Renderer, and Coordinator ownership.
 * • No avatar behavior migrated.
 *
 * Build 000015
 * - Added Avatar State Service ownership.
 * - Added runtime diagnostics for Avatar State Service delegation.
 *
 * Build 000017
 * - Added Avatar Relationship Service diagnostics.
 *
 * Build 000018
 * - Added Avatar Layout Service diagnostics.
 *
 * Build 000019
 * - Added Avatar Order Service diagnostics.
 *
 * Build 000020
 * - Added Avatar Renderer diagnostics.
 *
 * Build 000021
 * - Added Avatar Effects Runtime ownership and diagnostics.
 *
 * Build 000023
 * - Added Avatar Coordinator relationship lifecycle ownership and diagnostics.
 *
 * Build 000024
 * - Added Avatar Drag Controller ownership and diagnostics.
 *
 * Build 000032
 * - Added rendered avatar geometry ownership through AvatarRenderer.
 *
 * Build 000033
 * - Added Avatar Aura Service ownership and diagnostics.
 ******************************************************************************/

/**
 * @file avatar-runtime.js
 *
 * Defines the Avatar Runtime.
 *
 * AvatarRuntime is the public framework module responsible for coordinating
 * all avatar-related runtime components.
 *
 * Constructors establish module identity.
 *
 * Lifecycle methods perform runtime work.
 */

import {

    CoreModule

} from "../../core/core-module.js";

import {

    AvatarStateService

} from "./services/avatar-state-service.js";

import {

    AvatarRelationshipService

} from "./services/avatar-relationship-service.js";

import {

    AvatarOrderService

} from "./services/avatar-order-service.js";

import {

    AvatarLayoutService

} from "./services/avatar-layout-service.js";

import {

    AvatarAuraService

} from "./services/avatar-aura-service.js";

import {

    AvatarRenderer

} from "./renderers/avatar-renderer.js";

import {

    AvatarEffectsRuntime

} from "./effects/avatar-effects-runtime.js";

import {

    AvatarCoordinator

} from "./coordinators/avatar-coordinator.js";

import {

    AvatarDragController

} from "./controllers/avatar-drag-controller.js";

//--------------------------------------------------
// Avatar Runtime
//--------------------------------------------------

/**
 * Coordinates all avatar runtime components.
 *
 * AvatarRuntime owns the lifetime of all internal avatar runtime components.
 *
 * AvatarRuntime is the sole owner of all avatar runtime components.
 *
 * Internal runtime components are implementation details and are not exposed
 * as part of the runtime's public API.
 */
export class AvatarRuntime extends CoreModule {

    //--------------------------------------------------
    // Private Fields
    //--------------------------------------------------

    /**
     * State runtime component.
     */
    #state = null;

    /**
     * Relationship runtime component.
     */
    #relationships = null;

    /**
     * Order runtime component.
     */
    #order = null;

    /**
     * Layout runtime component.
     */
    #layout = null;

    /**
     * Renderer runtime component.
     */
    #renderer = null;

    /**
     * Aura workflow runtime component.
     */
    #aura = null;

    /**
     * Effects runtime component.
     */
    #effects = null;

    /**
     * Coordinator runtime component.
     */
    #coordinator = null;

    /**
     * Drag controller runtime component.
     */
    #drag = null;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Avatar Runtime.
     *
     * Constructors establish module identity only.
     *
     * Runtime initialization is performed during the framework lifecycle.
     */
    constructor() {

        super({

            id: "avatar-runtime",

            name: "Avatar Runtime",

            version: "1.0.0",

            description:
                "Coordinates avatar runtime components.",

            metadata: {}

        });

    }

    //--------------------------------------------------
    // Public Runtime Components
    //--------------------------------------------------

    /**
     * Returns the Avatar State Service.
     *
     * @returns {AvatarStateService}
     *         State runtime component.
     */
    get state() {

        return this.#state;

    }

    /**
     * Returns the Avatar Relationship Service.
     *
     * @returns {AvatarRelationshipService}
     *         Relationship runtime component.
     */
    get relationships() {

        return this.#relationships;

    }

    /**
     * Returns the Avatar Order Service.
     *
     * @returns {AvatarOrderService}
     *         Order runtime component.
     */
    get order() {

        return this.#order;

    }

    /**
     * Returns the Avatar Layout Service.
     *
     * @returns {AvatarLayoutService}
     *         Layout runtime component.
     */
    get layout() {

        return this.#layout;

    }

    /**
     * Returns the Avatar Renderer.
     *
     * @returns {AvatarRenderer}
     *         Renderer runtime component.
     */
    get renderer() {

        return this.#renderer;

    }

    /**
     * Returns the Avatar Aura Service.
     *
     * @returns {AvatarAuraService}
     *         Aura workflow runtime component.
     */
    get aura() {

        return this.#aura;

    }

    /**
     * Returns the Avatar Effects Runtime.
     *
     * @returns {AvatarEffectsRuntime}
     *         Effects runtime component.
     */
    get effects() {

        return this.#effects;

    }

    /**
     * Returns the Avatar Coordinator.
     *
     * @returns {AvatarCoordinator}
     *         Coordinator runtime component.
     */
    get coordinator() {

        return this.#coordinator;

    }

    /**
     * Returns the Avatar Drag Controller.
     *
     * @returns {AvatarDragController}
     *         Drag controller runtime component.
     */
    get drag() {

        return this.#drag;

    }

    //--------------------------------------------------
    // Public Diagnostics
    //--------------------------------------------------

    /**
     * Returns runtime diagnostic information.
     *
     * @returns {Object}
     *         Avatar Runtime diagnostics.
     */
    getDiagnostics() {

        return Object.freeze({

            id:
                this.id,

            name:
                this.name,

            build:
                this.build,

            state:
                this.#state?.getDiagnostics() ?? null,

            relationships:
                this.#relationships?.getDiagnostics() ?? null,

            order:
                this.#order?.getDiagnostics() ?? null,

            layout:
                this.#layout?.getDiagnostics() ?? null,

            renderer:
                this.#renderer?.getDiagnostics() ?? null,

            aura:
                this.#aura?.getDiagnostics() ?? null,

            effects:
                this.#effects?.getDiagnostics() ?? null,

            coordinator:
                this.#coordinator?.getDiagnostics() ?? null,

            drag:
                this.#drag?.getDiagnostics() ?? null

        });

    }


    //--------------------------------------------------
    // Protected Lifecycle Hooks
    //--------------------------------------------------

    /**
     * Called when the Avatar Runtime is initialized.
     *
     * Creates the runtime components owned by AvatarRuntime.
     */
    onInitialize() {

        this.#createState();

        this.#createRelationships();

        this.#createOrder();

        this.#createLayout();

        this.#createRenderer();

        this.#createAura();

        this.#createEffects();

        this.#createCoordinator();

        this.#createDrag();

    }

    /**
     * Called when the Avatar Runtime is started.
     *
     * Derived runtime behavior may override as required.
     */
    onStart() {

    }

    /**
     * Called when the Avatar Runtime is stopped.
     *
     * Derived runtime behavior may override as required.
     */
    onStop() {

    }

    /**
     * Called when the Avatar Runtime is destroyed.
     *
     * Releases resources owned by the runtime.
     */
    onDestroy() {

        this.#drag?.destroy();

        this.#coordinator?.destroy();

        this.#effects?.destroy();

        this.#aura?.destroy();

        this.#renderer?.destroy();

        this.#layout?.destroy();

        this.#order?.destroy();

        this.#relationships?.destroy();

        this.#state?.destroy();

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    /**
     * Creates the State runtime component.
     */
    #createState() {

        this.#state =
            new AvatarStateService(
                this
            );

        this.#state.initialize();

    }

    /**
     * Creates the Relationship runtime component.
     */
    #createRelationships() {

        this.#relationships =
            new AvatarRelationshipService(
                this
            );

        this.#relationships.initialize();

    }

    /**
     * Creates the Order runtime component.
     */
    #createOrder() {

        this.#order =
            new AvatarOrderService(
                this
            );

        this.#order.initialize();

    }

    /**
     * Creates the Layout runtime component.
     */
    #createLayout() {

        this.#layout =
            new AvatarLayoutService(
                this
            );

        this.#layout.initialize();

    }

    /**
     * Creates the Renderer runtime component.
     */
    #createRenderer() {

        this.#renderer =
            new AvatarRenderer(
                this
            );

        this.#renderer.initialize();

    }

    /**
     * Creates the Aura runtime component.
     */
    #createAura() {

        this.#aura =
            new AvatarAuraService(
                this
            );

        this.#aura.initialize();

    }

    /**
     * Creates the Effects runtime component.
     */
    #createEffects() {

        this.#effects =
            new AvatarEffectsRuntime(
                this
            );

        this.#effects.initialize();

    }

    /**
     * Creates the Coordinator runtime component.
     */
    #createCoordinator() {

        this.#coordinator =
            new AvatarCoordinator(
                this
            );

        this.#coordinator.initialize();

    }

    /**
     * Creates the Drag Controller runtime component.
     */
    #createDrag() {

        this.#drag =
            new AvatarDragController(
                this
            );

        this.#drag.initialize();

    }

}

export default AvatarRuntime;

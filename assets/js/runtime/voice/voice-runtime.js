/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      voice-runtime.js
 *
 * Layer:
 *      Runtime
 *
 * Owner:
 *      Voice Runtime
 *
 * Purpose:
 *      Owns voice workflow runtime coordination.
 *
 * Build:
 *      000027
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000027
 * - Introduced VoiceRuntime foundation.
 * - Added VoiceMediaService ownership and diagnostics.
 ******************************************************************************/

/**
 * @file voice-runtime.js
 *
 * Defines the Voice Runtime.
 */

import {

    CoreModule

} from "../../core/core-module.js";

import {

    VoiceMediaService

} from "./services/voice-media-service.js";

import {

    WebcamViewerPolicyService

} from "./services/webcam-viewer-policy-service.js";

import {

    PrivateVoiceMembershipService

} from "./services/private-voice-membership-service.js";

import {

    VoiceTransmissionModeService

} from "./services/voice-transmission-mode-service.js";

//--------------------------------------------------
// Voice Runtime
//--------------------------------------------------

/**
 * Coordinates voice runtime components.
 */
export class VoiceRuntime extends CoreModule {

    //--------------------------------------------------
    // Private Fields
    //--------------------------------------------------

    /**
     * Voice media workflow service.
     */
    #media = null;

    /**
     * Current-viewer webcam presentation and receive policy.
     */
    #viewerPolicy = null;

    #privateVoice = null;

    #transmissionModes = null;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Voice Runtime.
     */
    constructor() {

        super({

            id:
                "voice-runtime",

            name:
                "Voice Runtime",

            version:
                "1.0.0",

            description:
                "Coordinates voice runtime components.",

            metadata:
                {}

        });

    }

    //--------------------------------------------------
    // Public Getters
    //--------------------------------------------------

    /**
     * Returns the Voice Media Service.
     *
     * @returns {VoiceMediaService}
     */
    get media() {

        return this.#media;

    }

    get viewerPolicy() {

        return this.#viewerPolicy;

    }

    get privateVoice() {

        return this.#privateVoice;

    }

    get transmissionModes() {

        return this.#transmissionModes;

    }

    //--------------------------------------------------
    // Public Diagnostics
    //--------------------------------------------------

    /**
     * Returns VoiceRuntime diagnostics.
     *
     * @returns {Object}
     */
    getDiagnostics() {

        return Object.freeze({

            owner:
                "VoiceRuntime",

            build:
                "000027",

            media:
                this.#media?.getDiagnostics() ?? null,

            viewerPolicy:
                this.#viewerPolicy?.getDiagnostics() ?? null,

            privateVoice:
                this.#privateVoice?.snapshot() ?? null

        });

    }

    //--------------------------------------------------
    // Core Lifecycle
    //--------------------------------------------------

    /**
     * Creates runtime-owned voice components.
     */
    onInitialize() {

        this.#createViewerPolicyService();
        this.#createPrivateVoiceService();
        this.#createTransmissionModeService();
        this.#createMediaService();

    }

    /**
     * Releases runtime-owned voice components.
     */
    onDestroy() {

        this.#media?.destroy();
        this.#viewerPolicy?.destroy();
        this.#privateVoice?.destroy();
        this.#transmissionModes?.destroy();

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    /**
     * Creates the Voice Media Service runtime component.
     */
    #createMediaService() {

        this.#media =
            new VoiceMediaService(
                this
            );

        this.#media.initialize();

    }

    #createViewerPolicyService() {

        this.#viewerPolicy =
            new WebcamViewerPolicyService(
                this
            );

        this.#viewerPolicy.initialize();

    }

    #createPrivateVoiceService() {

        this.#privateVoice =
            new PrivateVoiceMembershipService();

    }

    #createTransmissionModeService() {

        this.#transmissionModes =
            new VoiceTransmissionModeService();

    }

}

export default VoiceRuntime;

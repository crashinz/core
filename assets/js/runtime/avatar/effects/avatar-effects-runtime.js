/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      avatar-effects-runtime.js
 *
 * Layer:
 *      Runtime Component
 *
 * Owner:
 *      Avatar Runtime
 *
 * Purpose:
 *      Owns avatar-local visual effects.
 *
 *      AvatarEffectsRuntime is responsible for avatar-local animation state,
 *      pixel avatar effects, pulse effects, and avatar effect cleanup.
 *
 *      AvatarEffectsRuntime does not own room effects, room-wide visual
 *      presentation, layout, ordering, relationship state, or business state.
 *
 * Build:
 *      000021
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000021
 * - Introduced AvatarEffectsRuntime.
 * - Migrated avatar pixel effect ownership from room.js.
 * - Migrated avatar pulse effect ownership from AvatarRenderer.
 * - Added avatar-local effect diagnostics.
 ******************************************************************************/

/**
 * @file avatar-effects-runtime.js
 *
 * Defines the Avatar Effects Runtime component.
 */

//
// No imports required.
//

//--------------------------------------------------
// Constants
//--------------------------------------------------

const DEFAULT_PARTICLE_COLORS = Object.freeze([
    "#7c6af7",
    "#27d3c3",
    "#f04f8b",
    "#f5c46b"
]);

//--------------------------------------------------
// Avatar Effects Runtime
//--------------------------------------------------

/**
 * Owns avatar-local visual effects.
 *
 * AvatarEffectsRuntime is owned exclusively by AvatarRuntime.
 */
export class AvatarEffectsRuntime {

    //--------------------------------------------------
    // Private Fields
    //--------------------------------------------------

    /**
     * Owning Avatar Runtime.
     */
    #runtime;

    /**
     * Number of active avatar effects.
     */
    #activeEffects = 0;

    /**
     * Number of avatar effects started.
     */
    #effectCount = 0;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Avatar Effects Runtime.
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
     * Initializes the avatar effects runtime.
     */
    initialize() {

        this.#activeEffects = 0;
        this.#effectCount = 0;

    }

    /**
     * Releases avatar effect state.
     */
    destroy() {

        this.#activeEffects = 0;

    }

    //--------------------------------------------------
    // Public Effects API
    //--------------------------------------------------

    /**
     * Runs a pixel materialize or dust effect for an avatar.
     *
     * @param {Object} participant
     *        Participant receiving the effect.
     *
     * @param {Object} options
     *        Effect options.
     *
     * @returns {Promise<void>}
     *          Resolves when the effect completes.
     */
    runPixelEffect(participant, options = {}) {

        const image = participant?.avatarEl;
        const stage = options.stage;

        if (!image || !stage) {
            return Promise.resolve();
        }

        const documentRef = options.document || document;
        const windowRef = options.window || window;
        const mode = options.mode === "out" ? "out" : "in";
        const rect = {
            left: image.offsetLeft,
            top: image.offsetTop,
            width: image.offsetWidth || 150,
            height: image.offsetHeight || 150
        };
        const columns = this.#activeEffects > 8 ? 6 : 8;
        const rows = this.#activeEffects > 8 ? 6 : 8;
        const pixelSize =
            Math.max(5, Math.ceil(rect.width / columns));
        const colorAt =
            this.#particleData(image, columns, rows, documentRef) ||
            (index => DEFAULT_PARTICLE_COLORS[index % DEFAULT_PARTICLE_COLORS.length]);
        const overlay = documentRef.createElement("div");

        overlay.className =
            `avatar-pixel-layer ${mode === "out" ? "dust-out" : "build-in"}`;
        overlay.style.left = `${rect.left}px`;
        overlay.style.top = `${rect.top}px`;
        overlay.style.width = `${rect.width}px`;
        overlay.style.height = `${rect.height}px`;
        stage.appendChild(overlay);

        this.#activeEffects += 1;
        this.#effectCount += 1;

        const duration = mode === "out" ? 780 : 880;
        const maxDelay = mode === "out" ? 300 : 360;
        let index = 0;

        for (let row = 0; row < rows; row += 1) {
            for (let column = 0; column < columns; column += 1) {
                const particle = documentRef.createElement("span");
                const x = (column / columns) * rect.width;
                const y = (row / rows) * rect.height;
                const fromBottom = rows - row - 1;
                const delay =
                    mode === "out"
                        ? row * (maxDelay / Math.max(1, rows - 1))
                        : fromBottom * (maxDelay / Math.max(1, rows - 1));
                const sideways =
                    (mode === "out" ? 1 : -1) *
                    (36 + Math.random() * 76) *
                    (Math.random() > 0.42 ? 1 : -0.65);
                const driftY =
                    mode === "out"
                        ? -18 + Math.random() * 54
                        : 40 + Math.random() * 60;

                particle.style.left = `${x}px`;
                particle.style.top = `${y}px`;
                particle.style.width = `${pixelSize}px`;
                particle.style.height = `${pixelSize}px`;
                particle.style.background = colorAt(index);
                particle.style.setProperty("--delay", `${delay}ms`);
                particle.style.setProperty("--dx", `${sideways.toFixed(1)}px`);
                particle.style.setProperty("--dy", `${driftY.toFixed(1)}px`);
                overlay.appendChild(particle);

                index += 1;
            }
        }

        image.classList.remove("avatar-materialize", "avatar-dusting");
        void image.offsetWidth;
        image.classList.add(mode === "out" ? "avatar-dusting" : "avatar-materialize");
        participant.labelEl?.classList.toggle("avatar-name-hidden", mode === "out");

        return new Promise(resolve => {
            windowRef.setTimeout(() => {
                overlay.remove();
                image.classList.remove("avatar-materialize", "avatar-dusting");
                participant.labelEl?.classList.remove("avatar-name-hidden");
                this.#activeEffects = Math.max(0, this.#activeEffects - 1);
                resolve();
            }, duration + maxDelay + 90);
        });

    }

    /**
     * Runs the avatar pulse effect for a participant.
     *
     * @param {Object} participant
     *        Participant receiving the pulse effect.
     *
     * @param {Object} options
     *        Effect options.
     *
     * @returns {void}
     */
    pulseParticipant(participant, options = {}) {

        const element =
            participant?.webcamVideoEl ||
            participant?.avatarEl;

        if (!element) return;

        const windowRef = options.window || window;

        this.#effectCount += 1;

        element.classList.remove("avatar-pulse");
        void element.offsetWidth;
        element.classList.add("avatar-pulse");

        windowRef.setTimeout(() => {
            element.classList.remove("avatar-pulse");
        }, 1500);

    }

    /**
     * Returns avatar effects diagnostic information.
     *
     * @returns {Object}
     *          Avatar effects diagnostics.
     */
    getDiagnostics() {

        return Object.freeze({

            owner:
                "AvatarEffectsRuntime",

            build:
                "000021",

            activeEffects:
                this.#activeEffects,

            effectCount:
                this.#effectCount

        });

    }

    /**
     * Returns the owning Avatar Runtime.
     *
     * @returns {AvatarRuntime}
     */
    get runtime() {

        return this.#runtime;

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    /**
     * Returns particle color data sampled from an avatar image.
     *
     * @param {HTMLImageElement} image
     *        Avatar image element.
     *
     * @param {number} columns
     *        Pixel columns.
     *
     * @param {number} rows
     *        Pixel rows.
     *
     * @param {Document} documentRef
     *        Document used to create sampling canvas.
     *
     * @returns {Function|null}
     *          Color lookup function.
     */
    #particleData(image, columns, rows, documentRef) {

        if (!image?.complete || !image.naturalWidth || !image.naturalHeight) {
            return null;
        }

        try {
            const canvas = documentRef.createElement("canvas");
            canvas.width = columns;
            canvas.height = rows;

            const context =
                canvas.getContext("2d", {
                    willReadFrequently: true
                });

            context.drawImage(image, 0, 0, columns, rows);

            const pixels =
                context.getImageData(0, 0, columns, rows).data;

            return index => {
                const offset = index * 4;
                const alpha = pixels[offset + 3];

                if (alpha < 24) {
                    return DEFAULT_PARTICLE_COLORS[index % DEFAULT_PARTICLE_COLORS.length];
                }

                return `rgba(${pixels[offset]}, ${pixels[offset + 1]}, ${pixels[offset + 2]}, ${Math.max(0.45, alpha / 255)})`;
            };

        } catch {
            return index =>
                DEFAULT_PARTICLE_COLORS[index % DEFAULT_PARTICLE_COLORS.length];
        }

    }

}

export default AvatarEffectsRuntime;

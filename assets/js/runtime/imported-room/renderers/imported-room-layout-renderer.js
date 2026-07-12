/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      imported-room-layout-renderer.js
 *
 * Layer:
 *      Runtime Renderer
 *
 * Owner:
 *      Imported Room Runtime
 *
 * Purpose:
 *      Owns imported room layout rendering, imported content presentation, and
 *      imported background synchronization.
 *
 * Build:
 *      000031
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000030
 * - Introduced ImportedRoomLayoutRenderer.
 * - Transferred imported room layout rendering from room.js.
 * Build 000031
 * - Applied imported page text and main-image sizing variables from imported
 *   layout data.
 ******************************************************************************/

/**
 * @file imported-room-layout-renderer.js
 *
 * Defines the Imported Room Layout Renderer.
 */

//
// No imports required.
//

//--------------------------------------------------
// Imported Room Layout Renderer
//--------------------------------------------------

/**
 * Owns imported room layout presentation.
 */
export class ImportedRoomLayoutRenderer {

    //--------------------------------------------------
    // Private Fields
    //--------------------------------------------------

    #runtime;

    #music;

    #context = null;

    #lastSectionCount = 0;

    #lastRendered = false;

    #lastInnerTranquillity = false;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Imported Room Layout Renderer.
     *
     * @param {ImportedRoomRuntime} runtime
     *        Owning Imported Room Runtime.
     *
     * @param {ImportedRoomMusicService} music
     *        Runtime-owned imported room music service.
     */
    constructor(runtime, music) {

        this.#runtime = runtime;
        this.#music = music;

    }

    //--------------------------------------------------
    // Public Lifecycle
    //--------------------------------------------------

    /**
     * Initializes the renderer.
     */
    initialize() {

    }

    /**
     * Releases imported room layout presentation references.
     */
    destroy() {

        this.clear();
        this.#context = null;

    }

    //--------------------------------------------------
    // Public Getters
    //--------------------------------------------------

    /**
     * Returns the owning Imported Room Runtime.
     *
     * @returns {ImportedRoomRuntime}
     */
    get runtime() {

        return this.#runtime;

    }

    //--------------------------------------------------
    // Public Configuration
    //--------------------------------------------------

    /**
     * Configures DOM shell callbacks and helpers.
     *
     * @param {Object} context
     */
    configure(context = {}) {

        this.#context = context;

    }

    //--------------------------------------------------
    // Public Presentation
    //--------------------------------------------------

    /**
     * Renders an imported room layout.
     *
     * @param {Object|null} layout
     */
    render(layout) {

        const layoutEl =
            this.#layoutElement();

        const stage =
            this.#stageElement();

        if (!layoutEl) return;

        if (!layout || !Array.isArray(layout.sections) || !layout.sections.length) {

            this.clear();
            return;

        }

        this.#lastSectionCount =
            layout.sections.length;

        const backgroundColor =
            this.#safeCssColor(
                layout.background_color,
                "#000000"
            );

        if (backgroundColor) {

            stage?.style.setProperty(
                "--vp-import-bg",
                backgroundColor
            );

        }

        const textColor =
            this.#safeCssColor(
                layout.text_color
            );

        if (textColor) {

            stage?.style.setProperty(
                "--vp-import-text",
                textColor
            );

        } else {

            stage?.style.removeProperty(
                "--vp-import-text"
            );

        }

        this.#syncAudioPlayerColors(
            layout
        );

        this.#syncImportedSizing(
            layout
        );

        this.syncBackgroundLayer();

        const chunks =
            this.#renderSections(
                layout.sections
            );

        this.#music?.prepareInlinePlayerRemoval(
            "imported-layout-render"
        );

        layoutEl.innerHTML =
            chunks.join("");

        this.#lastInnerTranquillity =
            chunks.join("").toLowerCase().includes(
                "inner-tranquillity"
            );

        this.#music?.applyInlinePlayerCompatibility({
            innerTranquillity:
                this.#lastInnerTranquillity,
            stage:
                stage,
            backgroundTile:
                Boolean(this.#config()?.backgroundTile),
            backgroundPath:
                this.#config()?.backgroundPath || ""
        });

        layoutEl.hidden =
            false;

        this.#lastRendered =
            true;

        this.syncBackgroundLayer();

    }

    /**
     * Clears imported room layout presentation.
     */
    clear() {

        const layoutEl =
            this.#layoutElement();

        const stage =
            this.#stageElement();

        if (layoutEl) {

            this.#music?.prepareInlinePlayerRemoval(
                "imported-layout-clear"
            );
            layoutEl.hidden = true;
            layoutEl.innerHTML = "";
            layoutEl.classList.remove(
                "has-import-background"
            );

        }

        stage?.style.removeProperty(
            "--vp-import-bg"
        );
        stage?.style.removeProperty(
            "--vp-import-bg-image"
        );
        stage?.style.removeProperty(
            "--vp-import-text"
        );
        stage?.style.removeProperty(
            "--vp-import-text-size"
        );
        stage?.style.removeProperty(
            "--vp-import-main-image-width"
        );
        stage?.style.removeProperty(
            "--vp-import-main-image-max-width"
        );
        stage?.style.removeProperty(
            "--vp-import-mobile-image-width"
        );

        this.#lastSectionCount = 0;
        this.#lastRendered = false;
        this.#lastInnerTranquillity = false;

    }

    /**
     * Synchronizes imported background tile presentation.
     */
    syncBackgroundLayer() {

        const layoutEl =
            this.#layoutElement();

        if (!layoutEl || layoutEl.hidden) return;

        const stage =
            this.#stageElement();

        if (this.#config()?.backgroundTile && this.#config()?.backgroundPath) {

            stage?.style.setProperty(
                "--vp-import-bg-image",
                `url("${this.#mediaUrl(this.#config().backgroundPath)}")`
            );

            layoutEl.classList.add(
                "has-import-background"
            );

        } else {

            stage?.style.removeProperty(
                "--vp-import-bg-image"
            );

            layoutEl.classList.remove(
                "has-import-background"
            );

        }

    }

    //--------------------------------------------------
    // Public Diagnostics
    //--------------------------------------------------

    /**
     * Returns imported room layout diagnostics.
     *
     * @returns {Object}
     */
    getDiagnostics() {

        return Object.freeze({

            owner:
                "ImportedRoomRuntime",

            build:
                "000031",

            configured:
                Boolean(this.#context),

            rendered:
                this.#lastRendered,

            lastSectionCount:
                this.#lastSectionCount,

            innerTranquillity:
                this.#lastInnerTranquillity

        });

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    #config() {

        return this.#context?.getConfig?.() || {};

    }

    #layoutElement() {

        return this.#context?.getLayoutElement?.() || null;

    }

    #stageElement() {

        return this.#context?.getStageElement?.() || null;

    }

    #mediaUrl(path) {

        return this.#context?.mediaUrl?.(
            path
        ) || path;

    }

    #esc(value) {

        return this.#context?.esc?.(
            value
        ) || String(value ?? "");

    }

    #safeCssColor(value, fallback = "") {

        const color =
            String(value || "").trim();

        if (/^#[0-9a-f]{3,8}$/i.test(color)) return color;
        if (/^(?:rgb|rgba|hsl|hsla)\([0-9.%\s,+-]+\)$/i.test(color)) return color;
        if (/^[a-z]{3,24}$/i.test(color)) return color;

        return fallback;

    }

    #safeCssSize(value) {

        const size =
            String(value || "").trim();

        return /^[0-9.]+(?:px|pt|em|rem|%)$/i.test(size) ? size : "";

    }

    #syncAudioPlayerColors(layout) {

        const stage =
            this.#stageElement();

        const playerBg =
            this.#safeCssColor(
                layout.audio_player_bg
            );

        const playerText =
            this.#safeCssColor(
                layout.audio_player_text_buttons
            );

        if (playerBg) {

            stage?.style.setProperty("--audio-player-bg", playerBg);
            stage?.style.setProperty("--audio-player-track-bg", playerBg);
            stage?.style.setProperty("--audio-player-progress-bg", playerBg);
            stage?.style.setProperty("--audio-player-volume-track", playerBg);

        } else {

            stage?.style.removeProperty("--audio-player-bg");
            stage?.style.removeProperty("--audio-player-track-bg");
            stage?.style.removeProperty("--audio-player-progress-bg");
            stage?.style.removeProperty("--audio-player-volume-track");

        }

        if (playerText) {

            stage?.style.setProperty("--audio-player-text-buttons", playerText);
            stage?.style.setProperty("--audio-player-icon-color", playerText);
            stage?.style.setProperty("--audio-player-progress", playerText);
            stage?.style.setProperty("--audio-player-progress-handle", playerText);
            stage?.style.setProperty("--audio-player-volume-fill", playerText);

        } else {

            stage?.style.removeProperty("--audio-player-text-buttons");
            stage?.style.removeProperty("--audio-player-icon-color");
            stage?.style.removeProperty("--audio-player-progress");
            stage?.style.removeProperty("--audio-player-progress-handle");
            stage?.style.removeProperty("--audio-player-volume-fill");

        }

    }

    #syncImportedSizing(layout) {

        const stage =
            this.#stageElement();

        const textSize =
            this.#safeCssSize(
                layout.text_size
            );

        if (textSize) {

            stage?.style.setProperty(
                "--vp-import-text-size",
                textSize
            );

        } else {

            stage?.style.removeProperty(
                "--vp-import-text-size"
            );

        }

        const mainImageWidth =
            this.#safeCssSize(
                layout.main_image_width
            );

        if (mainImageWidth) {

            stage?.style.setProperty(
                "--vp-import-main-image-width",
                mainImageWidth
            );

        } else {

            stage?.style.removeProperty(
                "--vp-import-main-image-width"
            );

        }

        const mainImageMaxWidth =
            this.#safeCssSize(
                layout.main_image_max_width
            );

        if (mainImageMaxWidth) {

            stage?.style.setProperty(
                "--vp-import-main-image-max-width",
                mainImageMaxWidth
            );

        } else {

            stage?.style.removeProperty(
                "--vp-import-main-image-max-width"
            );

        }

        const mobileImageWidth =
            this.#safeCssSize(
                layout.mobile_image_width
            );

        if (mobileImageWidth) {

            stage?.style.setProperty(
                "--vp-import-mobile-image-width",
                mobileImageWidth
            );

        } else {

            stage?.style.removeProperty(
                "--vp-import-mobile-image-width"
            );

        }

    }

    #renderSections(sections) {

        const chunks =
            [];

        let avatarRow =
            [];

        const firstTrack =
            this.#config()?.musicPlaylist?.[0] || null;

        let roomTrackInserted =
            false;

        const flushAvatarRow =
            () => {

                if (!avatarRow.length) return;

                chunks.push(
                    `<div class="vp-import-section vp-import-avatar-row">${avatarRow.map(section => this.#imageHtml(section)).join("")}</div>`
                );

                avatarRow = [];

            };

        sections.forEach(section => {

            if (
                firstTrack &&
                !roomTrackInserted &&
                section?.type === "text" &&
                String(section.text).trim().toLowerCase() === "inner-tranquillity"
            ) {

                const inlinePlayer =
                    this.#music?.inlinePlayerHtml(
                        firstTrack
                    ) || "";

                if (inlinePlayer) {

                    chunks.push(
                        inlinePlayer
                    );

                }

                roomTrackInserted = true;

            }

            if (this.#isAvatarSection(section)) {

                avatarRow.push(
                    section
                );

                return;

            }

            flushAvatarRow();

            if (section?.type === "image" && section.path && section.role === "avatar-piece") {

                chunks.push(
                    `<div class="vp-import-avatar-piece">
            ${this.#imageHtml(section)}
        </div>`
                );

                return;

            }

            if (section?.type === "image" && section.path) {

                chunks.push(
                    this.#imageHtml(
                        section
                    )
                );

                return;

            }

            if (section?.type === "text" && section.text) {

                chunks.push(
                    this.#textHtml(
                        section
                    )
                );

            }

        });

        flushAvatarRow();

        return chunks;

    }

    #imageHtml(section) {

        const roleClass =
            section.role ? ` vp-import-${String(section.role).replace(/[^a-z0-9_-]+/gi, "-")}` : "";

        const headerClass =
            section.role === "header" ? " vp-import-header" : "";

        return `<figure class="vp-import-section vp-import-image${headerClass}${roleClass}"><img src="${this.#esc(this.#mediaUrl(section.path))}" alt="${this.#esc(section.alt || "")}"></figure>`;

    }

    #isAvatarSection(section) {

        return section?.type === "image" &&
            section.path &&
            ["avatar-left", "avatar-right"].includes(section.role);

    }

    #textHtml(section) {

        const style =
            section.style || {};

        const inline =
            [
                this.#safeCssColor(style.color) ? `color:${this.#safeCssColor(style.color)}` : "",
                this.#safeCssSize(style.font_size) ? `font-size:${this.#safeCssSize(style.font_size)}` : "",
                ["left", "center", "right"].includes(style.text_align) ? `text-align:${style.text_align}` : ""
            ].filter(Boolean).join(";");

        return `<div class="vp-import-section vp-import-text"${inline ? ` style="${this.#esc(inline)}"` : ""}>${this.#esc(section.text).replace(/\n/g, "<br>")}</div>`;

    }

}

export default ImportedRoomLayoutRenderer;

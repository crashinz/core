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

        const playerCapability =
            this.#privatePlayerCapability();

        const chunks =
            this.#renderSections(
                layout.sections,
                playerCapability
            );

        this.#music?.prepareInlinePlayerRemoval(
            "imported-layout-render"
        );

        layoutEl.innerHTML =
            chunks.join("");

        this.#lastInnerTranquillity =
            playerCapability.relevant;

        this.#music?.applyInlinePlayerCompatibility({
            innerTranquillity:
                this.#lastInnerTranquillity,
            privatePlayerAvailable:
                playerCapability.available,
            privatePlayerUnavailableReason:
                playerCapability.reason,
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

        this.#syncAudioPlayerColors({});
        this.#syncImportedSizing({});

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

        for (const property of ['--vp-import-poem-image-width', '--vp-import-poem-image-max-width', '--vp-import-mobile-poem-image-width']) {
            stage?.style.removeProperty(property);
        }
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

        if (stage) {
            for (const [field, property] of [
                ['poem_image_width', '--vp-import-poem-image-width'],
                ['poem_image_max_width', '--vp-import-poem-image-max-width'],
                ['mobile_poem_image_width', '--vp-import-mobile-poem-image-width']
            ]) {
                const raw = String(layout?.[field] || '').trim();
                const value = raw === 'auto' && field !== 'poem_image_max_width'
                    ? 'auto' : this.#safeCssSize(raw);
                if (value) stage.style.setProperty(property, value);
                else stage.style.removeProperty(property);
            }

            stage.classList.toggle('vp-import-audio-frame-hidden', Boolean(layout.hide_audio_iframe));
            for (const name of JSON.parse(stage.dataset.importPlayerProperties || '[]')) stage.style.removeProperty(name);
            const applied = [];
            for (const [name, value] of Object.entries(layout.player_style || {})) {
                if (!/^--(?:audio-player-[a-z-]+|player-(?:width|height|accent|overlay-size|symbol-size|replay-size|tooltip-(?:bg|border|text))|(?:play|pause)-icon-(?:width|height))$/.test(name)) continue;
                const safe = this.#safeCssColor(value) || this.#safeCssSize(value);
                if (!safe) continue;
                stage.style.setProperty(name, safe);
                applied.push(name);
            }
            stage.dataset.importPlayerProperties = JSON.stringify(applied);
        }

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
            String(layout.main_image_width || '').trim().toLowerCase() === 'auto' ? 'auto' : this.#safeCssSize(
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
            String(layout.mobile_image_width || '').trim().toLowerCase() === 'auto' ? 'auto' : this.#safeCssSize(
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

    #renderSections(sections, playerCapability) {

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
                playerCapability.relevant &&
                firstTrack &&
                !roomTrackInserted &&
                section?.type === "text" &&
                String(section.text).trim().toLowerCase() === "inner-tranquillity"
            ) {

                const inlinePlayer =
                    this.#music?.inlinePlayerHtml(
                        firstTrack,
                        playerCapability
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

        const accessibleText = this.#config()?.importLayout?.accessible_text;
        if (Array.isArray(accessibleText) && accessibleText.length) {
            chunks.push(`<div class="vp-import-accessible-text" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip-path:inset(50%);white-space:nowrap">${accessibleText.slice(0, 80).map(text => this.#esc(String(text))).join("<br>")}</div>`);
        }

        return chunks;

    }

    #privatePlayerCapability() {

        const capability =
            this.#config()?.innerTranquillityPlayer || {};

        return {
            relevant: Boolean(capability.relevant),
            available: Boolean(capability.available),
            reason: String(capability.reason || "capability-unavailable")
        };

    }

    #imagePresentationStyle(section) {
        const source = section.image_style || {};
        const css = [];
        const modes = ['normal', 'multiply', 'screen', 'overlay', 'darken', 'lighten', 'color-dodge', 'color-burn', 'hard-light', 'soft-light', 'difference', 'exclusion', 'hue', 'saturation', 'color', 'luminosity'];
        if (modes.includes(source.blend_mode)) css.push('mix-blend-mode:' + source.blend_mode);
        if (/^(?:0(?:\.[0-9]+)?|1(?:\.0+)?|\.[0-9]+)$/.test(String(source.opacity ?? ''))) css.push('opacity:' + source.opacity);
        const stop = '(?:transparent|black|white|#000(?:000)?|#fff(?:fff)?)(?:\\s+(?:100|[0-9]{1,2})(?:\\.[0-9]+)?%)?';
        const gradient = 'linear-gradient\\(\\s*to\\s+(?:left|right|top|bottom)\\s*,\\s*' + stop + '(?:\\s*,\\s*' + stop + '){1,7}\\s*\\)';
        const mask = String(source.mask_image || '');
        if (mask === 'none' || (mask.length <= 800 && new RegExp('^' + gradient + '(?:\\s*,\\s*' + gradient + '){0,3}$', 'i').test(mask))) {
            css.push('mask-image:' + mask, '-webkit-mask-image:' + mask);
        }
        const composites = {add: 'source-over', subtract: 'source-out', intersect: 'source-in', exclude: 'xor'};
        if (Object.hasOwn(composites, source.mask_composite)) css.push('mask-composite:' + source.mask_composite, '-webkit-mask-composite:' + composites[source.mask_composite]);
        if (css.length) css.push('filter:none');
        return css.join(';');
    }

    #detailMask(section) {
        const mask = String(section.image_style?.detail_mask_image || '');
        if (!mask || mask.length > 800) return '';
        const percent = '(?:100|[0-9]{1,2})(?:\\.[0-9]+)?%';
        const stop = '(?:transparent|black|white|#000(?:000)?|#fff(?:fff)?)(?:\\s+' + percent + ')?';
        const gradient = 'radial-gradient\\(\\s*ellipse\\s+' + percent + '\\s+' + percent
            + '\\s+at\\s+' + percent + '\\s+' + percent + '\\s*,\\s*'
            + stop + '(?:\\s*,\\s*' + stop + '){1,7}\\s*\\)';
        return new RegExp('^' + gradient + '(?:\\s*,\\s*' + gradient + '){0,3}$', 'i').test(mask) ? mask : '';
    }

    #imageHtml(section) {

        const roleClass =
            section.role ? ` vp-import-${String(section.role).replace(/[^a-z0-9_-]+/gi, "-")}` : "";

        const headerClass =
            section.role === "header" ? " vp-import-header" : "";

        const imageStyle = this.#imagePresentationStyle(section);
        const source = this.#esc(this.#mediaUrl(section.path));
        const baseImage = `<img style="${this.#esc(imageStyle)}" src="${source}" alt="${this.#esc(section.alt || "")}">`;
        const detailMask = this.#detailMask(section);
        const detailImage = detailMask
            ? `<img class="vp-import-artwork-detail" src="${source}" alt="" aria-hidden="true" draggable="false" style="mask-image:${this.#esc(detailMask)};-webkit-mask-image:${this.#esc(detailMask)}">`
            : '';
        const content = detailImage ? `<span class="vp-import-artwork-composite">${baseImage}${detailImage}</span>` : baseImage;
        return `<figure class="vp-import-section vp-import-image${headerClass}${roleClass}">${content}</figure>`;

    }

    #isAvatarSection(section) {

        return section?.type === "image" &&
            section.path &&
            ["avatar-left", "avatar-right"].includes(section.role);

    }

    #safeTextLink(value) {
        if (typeof value !== 'string' || /[\x00-\x20\x7f]/.test(value)) return '';
        try {
            const url = new URL(value);
            return ['https:', 'http:'].includes(url.protocol) && !url.username && !url.password ? url.href : '';
        } catch { return ''; }
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

        const runs = Array.isArray(section.runs) && section.runs.length ? section.runs.slice(0, 1800) : [section];
        const content = runs.map(run => {
            if (!run || typeof run.text !== 'string') return '';
            const runStyle = run.style || {};
            const runCss = [
                this.#safeCssColor(runStyle.color) ? `color:${this.#safeCssColor(runStyle.color)}` : '',
                this.#safeCssSize(runStyle.font_size) ? `font-size:${this.#safeCssSize(runStyle.font_size)}` : ''
            ].filter(Boolean).join(';');
            const text = this.#esc(run.text).replace(/\n/g, '<br>');
            const href = this.#safeTextLink(runStyle.link_href);
            const value = href ? `<a href="${this.#esc(href)}" target="_blank" rel="noopener noreferrer" style="color:inherit;font:inherit">${text}</a>` : text;
            return `<span${runCss ? ` style="${this.#esc(runCss)}"` : ''}>${value}</span>`;
        }).join('');
        return `<div class="vp-import-section vp-import-text"${inline ? ` style="${this.#esc(inline)}"` : ""}>${content}</div>`;

    }

}

export default ImportedRoomLayoutRenderer;

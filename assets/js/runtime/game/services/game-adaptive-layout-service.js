/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      game-adaptive-layout-service.js
 *
 * Owner:
 *      Game Runtime
 *
 * Purpose:
 *      Owns viewport-based, presentation-only game/chat fitting and exact
 *      ordinary-room layout restoration.
 *
 * Build:
 *      000056
 ******************************************************************************/

const DEFAULT_SIZING = Object.freeze({
    preferredAspectRatio: 4 / 3,
    minimumReadableWidth: 640,
    minimumReadableHeight: 480
});

const CHAT_MINIMUM_HEIGHT = 220;
// Reserve the complete game header, session status, surface actions, and frame
// spacing rather than only the legacy compact header allowance.
const STAGE_CHROME_HEIGHT = 260;
const LAYOUT_GUTTER_HEIGHT = 26;

export class GameAdaptiveLayoutService {

    #runtime;
    #context = null;
    #visible = false;
    #sizing = DEFAULT_SIZING;
    #resizeObserver = null;
    #resizeHandler = null;
    #focusReturn = null;
    #lastMeasurement = null;

    constructor(runtime) {
        this.#runtime = runtime;
    }

    initialize() {}

    configure(context = {}) {
        this.#context = context;
    }

    destroy() {
        this.setSurfaceVisibility(false, { terminal: false, destroying: true });
        this.#context = null;
    }

    setSurfaceVisibility(visible, details = {}) {
        const nextVisible = Boolean(visible);
        const main = this.#context?.getMainElement?.();
        const stage = this.#context?.getGameStageElement?.();
        if (!main || !stage) return null;

        if (nextVisible) {
            this.#sizing = this.#validatedSizing(details.sizing);
            if (!this.#visible) {
                const active = this.#context?.document?.activeElement || null;
                if (active && !stage.contains(active)) this.#focusReturn = active;
            }
            this.#visible = true;
            main.classList.add("game-surface-visible");
            stage.style.setProperty("--game-preferred-aspect-ratio", String(this.#sizing.preferredAspectRatio));
            stage.style.setProperty("--game-minimum-readable-width", `${this.#sizing.minimumReadableWidth}px`);
            stage.style.setProperty("--game-minimum-readable-height", `${this.#sizing.minimumReadableHeight}px`);
            this.#startResizeOwnership();
            return this.recalculate(details.reason || "surface-visible");
        }

        const wasVisible = this.#visible;
        this.#visible = false;
        this.#stopResizeOwnership();
        main.classList.remove("game-surface-visible");
        delete main.dataset.gameLayout;
        main.style.removeProperty("--game-room-height");
        stage.style.removeProperty("--game-preferred-aspect-ratio");
        stage.style.removeProperty("--game-minimum-readable-width");
        stage.style.removeProperty("--game-minimum-readable-height");
        this.#lastMeasurement = null;

        if (wasVisible) {
            this.#context?.onAdaptiveGameLayout?.({
                visible: false,
                mode: "ordinary-room",
                terminal: Boolean(details.terminal),
                reason: details.reason || "surface-hidden"
            });
        }

        if (details.terminal && !details.destroying) this.#restoreSafeFocus(stage);
        return null;
    }

    recalculate(reason = "viewport-change") {
        if (!this.#visible) return null;
        const main = this.#context?.getMainElement?.();
        if (!main) return null;

        const rect = main.getBoundingClientRect();
        const availableWidth = Math.max(0, Math.floor(rect.width - 20));
        const availableHeight = Math.max(0, Math.floor(rect.height - 20));
        const playerRails = availableWidth >= 1100 ? 336 : 208;
        // Responsive first-party games remain usable below their preferred
        // board dimensions. Keep the ordinary game/chat split until the
        // actual chat column becomes too narrow; vertical and board overflow
        // are owned by their scroll containers instead of hiding chat behind
        // the full-screen overlay.
        const minimumCoexistWidth = Math.min(this.#sizing.minimumReadableWidth, 420);
        const collapsed = availableWidth < minimumCoexistWidth;
        const mode = collapsed ? "overlay" : "coexist";

        main.dataset.gameLayout = mode;
        if (collapsed) {
            main.style.removeProperty("--game-room-height");
        } else {
            const frameWidth = Math.max(this.#sizing.minimumReadableWidth, availableWidth - playerRails - 40);
            const naturalStageHeight = Math.ceil(frameWidth / this.#sizing.preferredAspectRatio) + STAGE_CHROME_HEIGHT;
            const maximumStageHeight = Math.max(
                this.#sizing.minimumReadableHeight + STAGE_CHROME_HEIGHT,
                availableHeight - CHAT_MINIMUM_HEIGHT - LAYOUT_GUTTER_HEIGHT
            );
            const stageHeight = Math.min(
                maximumStageHeight,
                Math.max(this.#sizing.minimumReadableHeight + STAGE_CHROME_HEIGHT, naturalStageHeight)
            );
            const percentage = Math.max(48, Math.min(78, (stageHeight / Math.max(1, rect.height)) * 100));
            main.style.setProperty("--game-room-height", `${percentage.toFixed(3)}%`);
        }

        this.#lastMeasurement = Object.freeze({
            visible: true,
            mode,
            reason,
            viewportWidth: Math.floor(this.#context?.window?.innerWidth || 0),
            viewportHeight: Math.floor(this.#context?.window?.innerHeight || 0),
            contentWidth: Math.floor(rect.width),
            contentHeight: Math.floor(rect.height),
            chatMinimumHeight: CHAT_MINIMUM_HEIGHT,
            sizing: Object.freeze({ ...this.#sizing })
        });
        this.#context?.onAdaptiveGameLayout?.(this.#lastMeasurement);
        return this.#lastMeasurement;
    }

    getDiagnostics() {
        return Object.freeze({
            owner: "GameRuntime",
            build: "000056",
            visible: this.#visible,
            lastMeasurement: this.#lastMeasurement
        });
    }

    #validatedSizing(candidate) {
        const value = candidate && typeof candidate === "object" ? candidate : {};
        const aspect = Number(value.preferredAspectRatio);
        const width = Number(value.minimumReadableWidth);
        const height = Number(value.minimumReadableHeight);
        return Object.freeze({
            preferredAspectRatio: Number.isFinite(aspect) && aspect >= 0.5 && aspect <= 3
                ? aspect : DEFAULT_SIZING.preferredAspectRatio,
            minimumReadableWidth: Number.isInteger(width) && width >= 320 && width <= 1600
                ? width : DEFAULT_SIZING.minimumReadableWidth,
            minimumReadableHeight: Number.isInteger(height) && height >= 240 && height <= 1200
                ? height : DEFAULT_SIZING.minimumReadableHeight
        });
    }

    #startResizeOwnership() {
        if (this.#resizeObserver || this.#resizeHandler) return;
        const main = this.#context?.getMainElement?.();
        const ResizeObserverOwner = this.#context?.window?.ResizeObserver;
        if (main && ResizeObserverOwner) {
            this.#resizeObserver = new ResizeObserverOwner(() => this.recalculate("content-resize"));
            this.#resizeObserver.observe(main);
            return;
        }
        this.#resizeHandler = () => this.recalculate("window-resize");
        this.#context?.window?.addEventListener?.("resize", this.#resizeHandler, { passive: true });
    }

    #stopResizeOwnership() {
        this.#resizeObserver?.disconnect?.();
        this.#resizeObserver = null;
        if (this.#resizeHandler) {
            this.#context?.window?.removeEventListener?.("resize", this.#resizeHandler);
            this.#resizeHandler = null;
        }
    }

    #restoreSafeFocus(stage) {
        const documentOwner = this.#context?.document;
        const active = documentOwner?.activeElement || null;
        const returnTarget = this.#focusReturn?.isConnected ? this.#focusReturn : null;
        const fallback = this.#context?.getGameStartButtonElement?.();
        if (!active || active === documentOwner?.body || stage.contains(active) || !active.isConnected) {
            (returnTarget || fallback)?.focus?.({ preventScroll: true });
        }
        this.#focusReturn = null;
    }
}

export default GameAdaptiveLayoutService;

/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      game-stage-renderer.js
 *
 * Layer:
 *      Runtime Renderer
 *
 * Owner:
 *      Game Runtime
 *
 * Purpose:
 *      Owns embedded game list and stage presentation.
 *
 * Build:
 *      000028
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000028
 * - Introduced GameStageRenderer.
 * - Transferred game stage presentation ownership from room.js.
 ******************************************************************************/

/**
 * @file game-stage-renderer.js
 *
 * Defines the Game Stage Renderer.
 */

//
// No imports required.
//

//--------------------------------------------------
// Game Stage Renderer
//--------------------------------------------------

/**
 * Owns embedded game list and stage presentation.
 */
export class GameStageRenderer {

    //--------------------------------------------------
    // Private Fields
    //--------------------------------------------------

    #runtime;

    #context = null;

    #lastRenderedGameCount = 0;

    #stageVisible = false;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Game Stage Renderer.
     *
     * @param {GameRuntime} runtime
     *        Owning Game Runtime.
     */
    constructor(runtime) {

        this.#runtime = runtime;

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
     * Releases renderer references.
     */
    destroy() {

        this.hideStage();
        this.#context = null;

    }

    //--------------------------------------------------
    // Public Getters
    //--------------------------------------------------

    /**
     * Returns the owning Game Runtime.
     *
     * @returns {GameRuntime}
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
     * Renders the active game list.
     *
     * @param {Array<Object>} games
     * @param {Object} stageContext
     */
    renderGameList(games, stageContext) {

        const listEl =
            this.#context?.getGameListElement?.();

        if (!listEl) return;

        this.#lastRenderedGameCount =
            games.length;

        listEl.innerHTML =
            "";

        listEl.hidden =
            !games.length;

        games.forEach(game => {

            const row =
                this.#context?.document?.createElement(
                    "div"
                );

            if (!row) return;

            const active =
                stageContext.activeGame?.lobby_code === game.lobby_code;

            row.className =
                `game-row${active ? " active" : ""}`;

            const inGame =
                (game.players || []).some(player =>
                    Number(player.participant_id) === stageContext.myParticipantId
                );

            const players =
                (game.players || []).map(player =>
                    `<img src="${this.#esc(this.#mediaUrl(player.avatar_url))}" alt="${this.#esc(player.display_name)}" title="${this.#esc(player.display_name)}">`
                ).join("");

            const action =
                inGame ?
                    "<span class=\"game-row-state\">In-game</span>" :
                    "<button class=\"btn\">Open</button>";

            row.innerHTML =
                `<div class="game-row-main"><strong class="game-row-title"><img src="${this.#esc(stageContext.gameIconUrl(game.game_type))}" alt="">${this.#esc(stageContext.gameName(game.game_type))}</strong><div class="minor">Started by ${this.#esc(game.started_by_name)}</div><div class="game-row-players">${players || "<span class=\"minor\">Waiting for players</span>"}</div></div>${action}`;

            row.querySelector("button")?.addEventListener(
                "click",
                () => stageContext.openGame(game)
            );

            if (inGame) {

                row.addEventListener(
                    "click",
                    () => stageContext.openGame(game)
                );

            }

            listEl.appendChild(
                row
            );

        });

    }

    /**
     * Shows the game stage for the active game.
     *
     * @param {Object} game
     * @param {Object} stageContext
     */
    showStage(game, stageContext) {

        const titleEl =
            this.#context?.getStageTitleElement?.();

        if (titleEl) {

            titleEl.textContent =
                stageContext.gameName(game.game_type);

        }

        const iconEl =
            this.#context?.getStageIconElement?.();

        if (iconEl) {

            iconEl.src =
                stageContext.gameIconUrl(game.game_type);

            iconEl.hidden =
                false;

        }

        const frameEl =
            this.#context?.getGameFrameElement?.();

        if (frameEl) {

            frameEl.src =
                stageContext.gameFrameUrl(game);

        }

    }

    /**
     * Hides the game stage.
     */
    hideStage() {

        const frameEl =
            this.#context?.getGameFrameElement?.();

        if (frameEl) {

            frameEl.src =
                "about:blank";

        }

        this.setLayerVisibility(
            false
        );

    }

    /**
     * Sets game stage visibility.
     *
     * @param {boolean} visible
     */
    setLayerVisibility(visible) {

        const stageEl =
            this.#context?.getGameStageElement?.();

        if (!stageEl) return;

        this.#stageVisible =
            Boolean(visible);

        stageEl.hidden =
            !this.#stageVisible;

    }

    /**
     * Renders active game stage player cards.
     *
     * @param {Object} game
     * @param {Object} stageContext
     */
    renderStagePlayers(game, stageContext) {

        const bySeat =
            new Map(
                (game.players || []).map(player => [
                    Number(player.seat),
                    player
                ])
            );

        [
            [this.#context?.getPlayerOneElement?.(), bySeat.get(1), "Player 1"],
            [this.#context?.getPlayerTwoElement?.(), bySeat.get(2), "Player 2"]
        ].forEach(([card, player, label]) => {

            if (!card) return;

            const img =
                card.querySelector("img");

            const name =
                card.querySelector("strong");

            const sub =
                card.querySelector(".minor");

            if (img) {

                img.src =
                    this.#mediaUrl(
                        player?.avatar_url ||
                        this.#context?.appUrl?.("/assets/images/baghead.png")
                    );

            }

            if (name) {

                name.textContent =
                    player?.display_name || "Waiting";

            }

            if (sub) {

                sub.className =
                    `minor game-player-role${player && Number(player.participant_id) === stageContext.myParticipantId ? " is-you" : ""}`;

                sub.textContent =
                    player ?
                        stageContext.gameSeatRole(
                            game.game_type,
                            player.seat || (label === "Player 1" ? 1 : 2)
                        ) :
                        `${label} open`;

            }

            card.dataset.participantId =
                player?.participant_id || "";

            card.classList.toggle(
                "typing",
                Boolean(player && this.#context?.isGameTyping?.(player.participant_id))
            );

        });

    }

    /**
     * Sends a control action to the game iframe.
     *
     * @param {string} action
     */
    postControl(action) {

        this.#context?.getGameFrameElement?.()
            ?.contentWindow
            ?.postMessage(
                {
                    type:
                        "game_control",

                    action
                },
                this.#context?.origin?.() || ""
            );

    }

    //--------------------------------------------------
    // Public Diagnostics
    //--------------------------------------------------

    /**
     * Returns game stage diagnostics.
     *
     * @returns {Object}
     */
    getDiagnostics() {

        return Object.freeze({

            owner:
                "GameRuntime",

            build:
                "000028",

            configured:
                Boolean(this.#context),

            lastRenderedGameCount:
                this.#lastRenderedGameCount,

            stageVisible:
                this.#stageVisible

        });

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    #esc(value) {

        return this.#context?.esc?.(
            value
        ) ?? String(value ?? "");

    }

    #mediaUrl(value) {

        return this.#context?.mediaUrl?.(
            value
        ) ?? String(value ?? "");

    }

}

export default GameStageRenderer;

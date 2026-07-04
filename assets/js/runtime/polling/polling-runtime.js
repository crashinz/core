/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      polling-runtime.js
 *
 * Layer:
 *      Runtime
 *
 * Owner:
 *      Polling Runtime
 *
 * Purpose:
 *      Owns recurring framework work.
 *
 *      PollingRuntime provides a runtime-owned scheduler for recurring jobs
 *      that were previously started directly by the Composition Root.
 *
 * Build:
 *      000016
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000016
 * - Introduced PollingRuntime.
 * - Added recurring job registration, lifecycle ownership, and diagnostics.
 ******************************************************************************/

/**
 * @file polling-runtime.js
 *
 * Defines the Polling Runtime.
 */

import {

    CoreModule

} from "../../core/core-module.js";

//--------------------------------------------------
// Polling Runtime
//--------------------------------------------------

/**
 * Owns recurring framework work.
 */
export class PollingRuntime extends CoreModule {

    //--------------------------------------------------
    // Private Fields
    //--------------------------------------------------

    /**
     * Registered recurring jobs.
     */
    #jobs = null;

    /**
     * Runtime running state.
     */
    #running = false;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates the Polling Runtime.
     */
    constructor() {

        super({

            id: "polling-runtime",

            name: "Polling Runtime",

            version: "1.0.0",

            description:
                "Owns recurring framework work.",

            metadata: {}

        });

        this.#jobs = new Map();

    }

    //--------------------------------------------------
    // Public Job API
    //--------------------------------------------------

    /**
     * Registers a recurring job.
     *
     * @param {Object} job
     * @param {string} job.id
     * @param {Function} job.run
     * @param {number} job.interval
     *
     * @returns {Object}
     */
    registerJob({ id, run, interval }) {

        if (!id) {
            throw new TypeError("Job id is required.");
        }

        if (typeof run !== "function") {
            throw new TypeError("Job run callback is required.");
        }

        if (!Number.isFinite(interval) || interval <= 0) {
            throw new TypeError("Job interval must be a positive number.");
        }

        this.unregisterJob(id);

        const job = {

            id,

            run,

            interval,

            timer:
                null,

            runs:
                0,

            failures:
                0,

            lastRunAt:
                null,

            lastError:
                null,

            inFlight:
                false,

            skippedRuns:
                0,

            paused:
                false

        };

        this.#jobs.set(id, job);

        if (this.#running) {
            this.#startJob(job);
        }

        return Object.freeze({

            id:
                job.id,

            interval:
                job.interval

        });

    }

    /**
     * Unregisters a recurring job.
     *
     * @param {string} id
     *
     * @returns {boolean}
     */
    unregisterJob(id) {

        const job = this.#jobs.get(id);

        if (!job) {
            return false;
        }

        this.#stopJob(job);

        return this.#jobs.delete(id);

    }

    /**
     * Pauses a recurring job.
     *
     * @param {string} id
     */
    pauseJob(id) {

        const job = this.#jobs.get(id);

        if (!job) {
            return;
        }

        job.paused = true;

        this.#stopJob(job);

    }

    /**
     * Resumes a recurring job.
     *
     * @param {string} id
     */
    resumeJob(id) {

        const job = this.#jobs.get(id);

        if (!job) {
            return;
        }

        job.paused = false;

        if (this.#running) {
            this.#startJob(job);
        }

    }

    //--------------------------------------------------
    // Public Diagnostics
    //--------------------------------------------------

    /**
     * Returns runtime diagnostic information.
     *
     * @returns {Object}
     */
    getDiagnostics() {

        return Object.freeze({

            id:
                this.id,

            name:
                this.name,

            build:
                this.build,

            running:
                this.#running,

            jobs:
                Array.from(
                    this.#jobs.values(),
                    job => Object.freeze({

                        id:
                            job.id,

                        interval:
                            job.interval,

                        running:
                            Boolean(job.timer),

                        paused:
                            job.paused,

                        runs:
                            job.runs,

                        failures:
                            job.failures,

                        lastRunAt:
                            job.lastRunAt,

                        lastError:
                            job.lastError,

                        inFlight:
                            job.inFlight,

                        skippedRuns:
                            job.skippedRuns

                    })
                )

        });

    }

    //--------------------------------------------------
    // Protected Lifecycle Hooks
    //--------------------------------------------------

    /**
     * Starts registered recurring jobs.
     */
    onStart() {

        this.#running = true;

        for (const job of this.#jobs.values()) {
            this.#startJob(job);
        }

    }

    /**
     * Stops registered recurring jobs.
     */
    onStop() {

        this.#running = false;

        for (const job of this.#jobs.values()) {
            this.#stopJob(job);
        }

    }

    /**
     * Releases scheduler resources.
     */
    onDestroy() {

        this.onStop();

        this.#jobs.clear();

    }

    //--------------------------------------------------
    // Private Methods
    //--------------------------------------------------

    /**
     * Starts a recurring job.
     *
     * @param {Object} job
     */
    #startJob(job) {

        if (job.paused || job.timer) {
            return;
        }

        job.timer = setInterval(
            () => this.#runJob(job),
            job.interval
        );

    }

    /**
     * Stops a recurring job.
     *
     * @param {Object} job
     */
    #stopJob(job) {

        if (!job.timer) {
            return;
        }

        clearInterval(job.timer);

        job.timer = null;

    }

    /**
     * Executes a recurring job.
     *
     * @param {Object} job
     */
    async #runJob(job) {

        if (job.inFlight) {

            job.skippedRuns += 1;

            return;

        }

        try {

            job.inFlight = true;

            job.runs += 1;

            job.lastRunAt = Date.now();

            await job.run();

            job.lastError = null;

        } catch (error) {

            job.failures += 1;

            job.lastError =
                error?.message || "Polling job failed.";

        } finally {

            job.inFlight = false;

        }

    }

}

export default PollingRuntime;

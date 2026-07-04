/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      core-errors.js
 *
 * Layer:
 *      1 - Foundation
 *
 * Purpose:
 *      Defines the permanent framework error hierarchy used throughout the
 *      Chat Runtime Framework.
 *
 *      Every framework exception derives from CoreError and provides a
 *      consistent, structured error model for diagnostics, logging, and
 *      developer tooling.
 *
 * Build:
 *      000011
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000011
 * • Permanent Foundation Layer implementation.
 * • Introduced CoreError and framework error hierarchy.
 ******************************************************************************/

/**
 * @file core-errors.js
 *
 * Defines the framework error hierarchy.
 *
 * Every framework error derives from CoreError.
 *
 * Framework errors provide structured metadata in addition to the standard
 * JavaScript Error interface.
 */

import {
    BUILD,
    ERROR_CODES
} from "./core-types.js";

//--------------------------------------------------
// Core Error
//--------------------------------------------------

/**
 * Base class for all framework exceptions.
 *
 * Every framework error derives from CoreError.
 *
 * @extends Error
 */
export class CoreError extends Error {

    //--------------------------------------------------
    // Private Fields
    //--------------------------------------------------

    #build;

    #cause;

    #code;

    #context;

    #module;

    #name;

    #service;

    #timestamp;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates a new framework error.
     *
     * @param {Object} [options={}] Framework error options.
     * @param {string} options.message Error message.
     * @param {string} [options.code] Framework error code.
     * @param {string|null} [options.module] Module identifier.
     * @param {symbol|null} [options.service] Service identifier.
     * @param {Object|null} [options.context] Additional diagnostic context.
     * @param {Error|null} [options.cause] Original error.
     */

    constructor({

        message,

        code = ERROR_CODES.FRAMEWORK_INTERNAL_ERROR,

        module = null,

        service = null,

        context = null,

        cause = null

    } = {}) {

        super(message, { cause });

        // Preserve the correct prototype chain when extending Error.
        Object.setPrototypeOf(this, new.target.prototype);

        this.name = new.target.name;

        this.#name = new.target.name;

        this.#build = BUILD.NUMBER;

        this.#cause = cause;

        this.#code = code;

        this.#context = Object.freeze({
            ...(context ?? {})
        });

        this.#module = module;

        this.#service = service;

        this.#timestamp = Date.now();

    }

    //--------------------------------------------------
    // Public API
    //--------------------------------------------------

    /**
     * Framework build number.
     *
     * @returns {number}
     */
    get build() {

        return this.#build;

    }

    /**
     * Original error.
     *
     * @returns {Error|null}
     */
    get cause() {

        return this.#cause;

    }

    /**
     * Framework error code.
     *
     * @returns {string}
     */
    get code() {

        return this.#code;

    }

    /**
     * Returns immutable diagnostic context.
     *
     * @returns {Object}
     */
    get context() {

        return this.#context;

    }

    /**
     * Module identifier.
     *
     * @returns {string|null}
     */
    get module() {

        return this.#module;

    }

    /**
     * Framework error name.
     *
     * @returns {string}
     */
    get name() {

        return this.#name;

    }

    /**
     * Service identifier.
     *
     * @returns {symbol|null}
     */
    get service() {

        return this.#service;

    }

    /**
     * Timestamp.
     *
     * @returns {number}
     */
    get timestamp() {

        return this.#timestamp;

    }

    /**
     * Returns a serializable representation of this error.
     *
     * @returns {Object}
     */
    toJSON() {

        return Object.freeze({

            build: this.build,

            code: this.code,

            context: this.context,

            message: this.message,

            module: this.module,

            name: this.name,

            service: this.service,

            timestamp: this.timestamp

        });

    }

    /**
     * Returns a human-readable representation of this error.
     *
     * @returns {string}
     */
    toString() {

        return `[${this.code}] ${this.message}`;

    }

}

//--------------------------------------------------
// Framework Errors
//--------------------------------------------------

/**
 * Base class for semantic framework exceptions.
 *
 * FrameworkError exists to group framework-specific exception types while
 * inheriting the implementation provided by CoreError.
 *
 * Semantic subclasses provide type identity rather than additional behavior.
 * 
 * @extends CoreError
 */
export class FrameworkError extends CoreError {

}

/**
 * Framework configuration error.
 *
 * @extends FrameworkError
 */
export class ConfigurationError extends FrameworkError {

}

/**
 * Framework dependency error.
 *
 * @extends FrameworkError
 */
export class DependencyError extends FrameworkError {

}

/**
 * Framework lifecycle error.
 *
 * @extends FrameworkError
 */
export class LifecycleError extends FrameworkError {

}

/**
 * Framework module error.
 *
 * @extends FrameworkError
 */
export class ModuleError extends FrameworkError {

}

/**
 * Framework registration error.
 *
 * @extends FrameworkError
 */
export class RegistrationError extends FrameworkError {

}

/**
 * Framework service error.
 *
 * @extends FrameworkError
 */
export class ServiceError extends FrameworkError {

}

/**
 * Framework validation error.
 *
 * @extends FrameworkError
 */
export class ValidationError extends FrameworkError {

}
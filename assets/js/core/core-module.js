/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      core-module.js
 *
 * Layer:
 *      1 - Foundation
 *
 * Purpose:
 *      Defines the permanent base class for all Chat Runtime Framework
 *      modules.
 *
 *      CoreModule establishes immutable module identity and provides the
 *      common interface from which all framework modules derive.
 *
 * Build:
 *      000011
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000011
 * • Permanent Foundation Layer implementation.
 * • Introduced CoreModule base class.
 ******************************************************************************/

/**
 * @file core-module.js
 *
 * Defines the permanent base class for framework modules.
 *
 * CoreModule establishes immutable module identity.
 *
 * Constructors establish identity only.
 *
 * Lifecycle methods perform work.
 */

import {
    ModuleError
} from "./core-errors.js";

import {
    assert,
    deepFreeze,
    isPlainObject,
    requireValue,
    shallowClone
} from "./core-utils.js";

import {
    BUILD
} from "./core-types.js";

//--------------------------------------------------
// Core Module
//--------------------------------------------------

/**
 * Base class for all framework modules.
 *
 * CoreModule establishes immutable module identity and defines the
 * lifecycle extension points shared by all framework modules.
 */
export class CoreModule {

    //--------------------------------------------------
    // Private Fields
    //--------------------------------------------------

    #build;

    #description;

    #id;

    #metadata;

    #name;

    #version;

    //--------------------------------------------------
    // Constructor
    //--------------------------------------------------

    /**
     * Creates a new framework module.
     *
     * @param {Object} [options={}] Module options.
     * @param {string} options.id Module identifier.
     * @param {string} options.name Module name.
     * @param {string} options.version Module version.
     * @param {string} [options.description] Module description.
     * @param {Object} [options.metadata={}] Plain object containing module metadata.
     *
     * @throws {ModuleError}
     */
    constructor({

        id,

        name,

        version,

        description = "",

        metadata = {}

    } = {}) {

        try {

            requireValue(id, "Module id is required.");

            requireValue(name, "Module name is required.");

            requireValue(version, "Module version is required.");

            assert(
                isPlainObject(metadata),
                "Module metadata must be a plain object."
            );

        } catch (error) {

            throw new ModuleError({

                message: error.message,

                cause: error

            });

        }

        this.#build = BUILD.NUMBER;

        this.#description = description;

        this.#id = id;

        this.#metadata = deepFreeze(
            shallowClone(metadata)
        );

        this.#name = name;

        this.#version = version;

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
     * Module description.
     *
     * @returns {string}
     */
    get description() {

        return this.#description;

    }

    /**
     * Module identifier.
     *
     * @returns {string}
     */
    get id() {

        return this.#id;

    }

    /**
     * Immutable module metadata.
     *
     * @returns {Object}
     */
    get metadata() {

        return this.#metadata;

    }

    /**
     * Module name.
     *
     * @returns {string}
     */
    get name() {

        return this.#name;

    }

    /**
     * Module version.
     *
     * @returns {string}
     */
    get version() {

        return this.#version;

    }

    /**
     * Returns a serializable representation of this module.
     *
     * @returns {Object}
     */
    toJSON() {

        return Object.freeze({

            build: this.build,

            description: this.description,

            id: this.id,

            metadata: this.metadata,

            name: this.name,

            version: this.version

        });

    }

    //--------------------------------------------------
    // Protected Lifecycle Hooks
    //--------------------------------------------------

    /**
     * Called when the module is initialized.
     *
     * Derived classes may override.
     */
    onInitialize() {

    }

    /**
     * Called when the module is started.
     *
     * Derived classes may override.
     */
    onStart() {

    }

    /**
     * Called when the module is stopped.
     *
     * Derived classes may override.
     */
    onStop() {

    }

    /**
     * Called when the module is destroyed.
     *
     * Derived classes may override.
     */
    onDestroy() {

    }

}
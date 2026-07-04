/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * File:
 *      core-utils.js
 *
 * Layer:
 *      1 - Foundation
 *
 * Purpose:
 *      Defines the permanent shared utility library used throughout the
 *      Chat Runtime Framework.
 *
 *      Utilities contained within this file are framework-agnostic,
 *      stateless, and reusable across all framework layers.
 *
 * Build:
 *      000011
 *
 * ---------------------------------------------------------------------------
 * Build History
 * ---------------------------------------------------------------------------
 * Build 000011
 * • Permanent Foundation Layer implementation.
 * • Introduced shared framework utility library.
 ******************************************************************************/

/**
 * @file core-utils.js
 *
 * Defines the permanent shared utility library for the framework.
 *
 * Utilities should remain:
 *
 * • Framework-agnostic.
 * • Stateless.
 * • Reusable.
 * • Composable.
 *
 * Utility functions should perform one clearly defined task and should
 * compose existing framework utilities whenever practical instead of
 * duplicating logic.
 */

import {
    ValidationError
} from "./core-errors.js";

//--------------------------------------------------
// Type Utilities
//--------------------------------------------------

/**
 * Determines whether a value is an array.
 *
 * @param {*} value Value to test.
 *
 * @returns {boolean}
 */
export function isArray(value) {

    return Array.isArray(value);

}

/**
 * Determines whether a value is a function.
 *
 * @param {*} value Value to test.
 *
 * @returns {boolean}
 */
export function isFunction(value) {

    return typeof value === "function";

}

/**
 * Determines whether a value is a non-null object.
 *
 * @param {*} value Value to test.
 *
 * @returns {boolean}
 */
export function isObject(value) {

    return value !== null
        && typeof value === "object";

}

/**
 * Determines whether a value is a string.
 *
 * @param {*} value Value to test.
 *
 * @returns {boolean}
 */
export function isString(value) {

    return typeof value === "string";

}

//--------------------------------------------------
// Object Utilities
//--------------------------------------------------

/**
 * Determines whether a value is a plain object.
 *
 * @param {*} value Value to test.
 *
 * @returns {boolean}
 */
export function isPlainObject(value) {

    if (!isObject(value)) {

        return false;

    }

    const prototype = Object.getPrototypeOf(value);

    return prototype === Object.prototype
        || prototype === null;

}

/**
 * Creates a shallow clone of a plain object.
 *
 * @param {Object} value Plain object to clone.
 *
 * @returns {Object}
 */
export function shallowClone(value) {

    if (!isPlainObject(value)) {

        return value;

    }

    return {

        ...value

    };

}

/**
 * Deeply freezes plain objects and arrays.
 *
 * @param {*} value Value to freeze.
 *
 * @returns {*}
 */
export function deepFreeze(value) {

    if (!isObject(value)) {

        return value;

    }

    if (Object.isFrozen(value)) {

        return value;

    }

    Object.freeze(value);

    if (isPlainObject(value)) {

        for (const property of Object.keys(value)) {

            deepFreeze(value[property]);

        }

    } else if (isArray(value)) {

        for (const item of value) {

            deepFreeze(item);

        }

    }

    return value;

}

//--------------------------------------------------
// String Utilities
//--------------------------------------------------

/**
 * Determines whether a value is blank.
 *
 * Blank values include:
 * - null
 * - undefined
 * - empty strings
 * - whitespace-only strings
 *
 * @param {*} value Value to test.
 *
 * @returns {boolean}
 */
export function isBlank(value) {

    if (value == null) {

        return true;

    }

    if (!isString(value)) {

        return false;

    }

    return value.trim().length === 0;

}

/**
 * Capitalizes the first character of a string.
 *
 * @param {string} value String to capitalize.
 *
 * @returns {string}
 */
export function capitalize(value) {

    if (!isString(value) || value.length === 0) {

        return value;

    }

    return value.charAt(0).toUpperCase()
        + value.slice(1);

}

//--------------------------------------------------
// Validation Utilities
//--------------------------------------------------

/**
 * Asserts that a condition is true.
 *
 * @param {boolean} condition Condition to evaluate.
 * @param {string} message Validation failure message.
 *
 * @throws {ValidationError}
 */
export function assert(condition, message) {

    if (condition) {

        return;

    }

    throw new ValidationError({

        message

    });

}

/**
 * Requires a value to be present.
 *
 * @param {*} value Value to validate.
 * @param {string} message Validation failure message.
 *
 * @returns {*}
 *
 * @throws {ValidationError}
 */
export function requireValue(value, message) {

    if (isBlank(value)) {

        throw new ValidationError({

            message

        });

    }

    return value;

}
/**
 * @module core/data/ShopwareError
 */
import utils from 'src/core/service/util.service';

/**
 * @class
 * @description Simple data structure to hold information about Api Errors.
 * @memberOf module:core/data/ShopwareError
 */
export default class ShopwareError {
    constructor({
        code,
        parameters = {},
        status = ''
    } = {}) {
        if (typeof code !== 'string' || code === '') {
            throw new Error('[ShopwareError] can not identify error by code');
        }

        this._id = utils.createId();
        this._code = code;
        this._parameters = parameters;
        this._status = status;
    }

    get id() {
        return this._id;
    }

    get code() {
        return this._code;
    }

    set code(value) {
        this._code = value;
    }

    get parameters() {
        return this._parameters;
    }

    set parameters(value) {
        this._parameters = value;
    }

    get status() {
        return this._status;
    }

    set status(value) {
        this._status = value;
    }

    get trace() {
        return this._trace;
    }

    set trace(value) {
        this._trace = value;
    }
}

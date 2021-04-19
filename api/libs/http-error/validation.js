/* eslint-disable */
"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.compileResponseValidationSchema = exports.addResponseValidation = exports.convertValidationErrors = exports.validationMessagesFormatters = exports.niceJoin = void 0;
const ajv_1 = __importDefault(require("ajv"));
const http_errors_enhanced_1 = require("http-errors-enhanced");
const interfaces_1 = require("./interfaces");
const utils_1 = require("./utils");
function niceJoin(array, lastSeparator = ' and ', separator = ', ') {
    switch (array.length) {
        case 0:
            return '';
        case 1:
            return array[0];
        case 2:
            return array.join(lastSeparator);
        default:
            return array.slice(0, array.length - 1).join(separator) + lastSeparator + array[array.length - 1];
    }
}
exports.niceJoin = niceJoin;
exports.validationMessagesFormatters = {
    contentType: () => 'only JSON payloads are accepted. Please set the "Content-Type" header to start with "application/json"',
    json: () => 'the body payload is not a valid JSON',
    jsonEmpty: () => 'the JSON body payload cannot be empty if the "Content-Type" header is set',
    missing: () => 'must be present',
    unknown: () => 'is not a valid property',
    uuid: () => 'must be a valid GUID (UUID v4)',
    timestamp: () => 'must be a valid ISO 8601 / RFC 3339 timestamp (example: 2018-07-06T12:34:56Z)',
    date: () => 'must be a valid ISO 8601 / RFC 3339 date (example: 2018-07-06)',
    time: () => 'must be a valid ISO 8601 / RFC 3339 time (example: 12:34:56)',
    uri: () => 'must be a valid URI',
    hostname: () => 'must be a valid hostname',
    ipv4: () => 'must be a valid IPv4',
    ipv6: () => 'must be a valid IPv6',
    paramType: (type) => {
        switch (type) {
            case 'integer':
                return 'must be a valid integer number';
            case 'number':
                return 'must be a valid number';
            case 'boolean':
                return 'must be a valid boolean (true or false)';
            case 'object':
                return 'must be a object';
            case 'array':
                return 'must be an array';
            default:
                return 'must be a string';
        }
    },
    presentString: () => 'must be a non empty string',
    minimum: (min) => `must be a number greater than or equal to ${min}`,
    maximum: (max) => `must be a number less than or equal to ${max}`,
    minimumProperties(min) {
        return min === 1 ? 'cannot be a empty object' : `must be a object with at least ${min} properties`;
    },
    maximumProperties(max) {
        return max === 0 ? 'must be a empty object' : `must be a object with at most ${max} properties`;
    },
    minimumItems(min) {
        return min === 1 ? 'cannot be a empty array' : `must be an array with at least ${min} items`;
    },
    maximumItems(max) {
        return max === 0 ? 'must be a empty array' : `must be an array with at most ${max} items`;
    },
    enum: (values) => `must be one of the following values: ${niceJoin(values.map((f) => `"${f}"`), ' or ')}`,
    pattern: (pattern) => `must match pattern "${pattern.replace(/\(\?:/g, '(')}"`,
    invalidResponseCode: (code) => `This endpoint cannot respond with HTTP status ${code}.`,
    invalidResponse: (code) => `The response returned from the endpoint violates its specification for the HTTP status ${code}.`,
    invalidFormat: (format) => `must match format "${format}" (format)`
};
function convertValidationErrors(section, data, validationErrors) {
    const errors = {};
    if (section === 'querystring') {
        section = 'query';
    }
    // For each error
    for (const e of validationErrors) {
        let message = '';
        let pattern;
        let value;
        let reason;
        // Normalize the key
        let key = e.dataPath;
        if (key.startsWith('.')) {
            key = key.substring(1);
        }
        // Remove useless quotes
        /* istanbul ignore next */
        if (key.startsWith('[') && key.endsWith(']')) {
            key = key.substring(1, key.length - 1);
        }
        // Depending on the type
        switch (e.keyword) {
            case 'required':
            case 'dependencies':
                key = e.params.missingProperty;
                message = exports.validationMessagesFormatters.missing();
                break;
            case 'additionalProperties':
                key = e.params.additionalProperty;
                message = exports.validationMessagesFormatters.unknown();
                break;
            case 'type':
                message = exports.validationMessagesFormatters.paramType(e.params.type);
                break;
            case 'minProperties':
                message = exports.validationMessagesFormatters.minimumProperties(e.params.limit);
                break;
            case 'maxProperties':
                message = exports.validationMessagesFormatters.maximumProperties(e.params.limit);
                break;
            case 'minItems':
                message = exports.validationMessagesFormatters.minimumItems(e.params.limit);
                break;
            case 'maxItems':
                message = exports.validationMessagesFormatters.maximumItems(e.params.limit);
                break;
            case 'minimum':
                message = exports.validationMessagesFormatters.minimum(e.params.limit);
                break;
            case 'maximum':
                message = exports.validationMessagesFormatters.maximum(e.params.limit);
                break;
            case 'enum':
                message = exports.validationMessagesFormatters.enum(e.params.allowedValues);
                break;
            case 'pattern':
                pattern = e.params.pattern;
                value = utils_1.get(data, key);
                if (pattern === '.+' && !value) {
                    message = exports.validationMessagesFormatters.presentString();
                }
                else {
                    message = exports.validationMessagesFormatters.pattern(e.params.pattern);
                }
                break;
            case 'format':
                reason = e.params.format;
                // Normalize the key
                if (reason === 'date-time') {
                    reason = 'timestamp';
                }
                message = (exports.validationMessagesFormatters[reason] || exports.validationMessagesFormatters.invalidFormat)(reason);
                break;
        }
        // No custom message was found, default to input one replacing the starting verb and adding some path info
        if (!message.length) {
            message = `${e.message.replace(/^should/, 'must')} (${e.keyword})`;
        }
        // Remove useless quotes
        /* istanbul ignore next */
        if (key.match(/(?:^['"])(?:[^.]+)(?:['"]$)/)) {
            key = key.substring(1, key.length - 1);
        }
        // Fix empty properties
        if (!key) {
            key = '$root';
        }
        key = key.replace(/^\//, '');
        errors[key] = message;
    }
    return { [section]: errors };
}
exports.convertValidationErrors = convertValidationErrors;
function addResponseValidation(route) {
    var _a;
    if (!((_a = route.schema) === null || _a === void 0 ? void 0 : _a.response)) {
        return;
    }
    const validators = {};
    /*
      Add these validators to the list of the one to compile once the server is started.
      This makes possible to handle shared schemas.
    */
    this[interfaces_1.kHttpErrorsEnhancedResponseValidations].push([
        this,
        validators,
        Object.entries(route.schema.response)
    ]);
    // Note that this hook is not called for non JSON payloads therefore validation is not possible in such cases
    route.preSerialization = async function (request, reply, payload) {
        const statusCode = reply.raw.statusCode;
        // Never validate error 500
        if (statusCode === http_errors_enhanced_1.INTERNAL_SERVER_ERROR) {
            return payload;
        }
        // No validator, it means the HTTP status is not allowed
        const validator = validators[statusCode];
        if (!validator) {
            if (request[interfaces_1.kHttpErrorsEnhancedConfiguration].allowUndeclaredResponses) {
                return payload;
            }
            throw new http_errors_enhanced_1.InternalServerError(exports.validationMessagesFormatters.invalidResponseCode(statusCode));
        }
        // Now validate the payload
        const valid = validator(payload);
        if (!valid) {
            throw new http_errors_enhanced_1.InternalServerError(exports.validationMessagesFormatters.invalidResponse(statusCode), {
                failedValidations: convertValidationErrors('response', payload, validator.errors)
            });
        }
        return payload;
    };
}
exports.addResponseValidation = addResponseValidation;
function compileResponseValidationSchema(configuration) {
    // Fix CJS/ESM interoperability
    // @ts-expect-error
    let AjvConstructor = ajv_1.default;
    /* istanbul ignore next */
    if (AjvConstructor.default) {
        AjvConstructor = AjvConstructor.default;
    }
    const hasCustomizer = typeof configuration.responseValidatorCustomizer === 'function';
    for (const [instance, validators, schemas] of this[interfaces_1.kHttpErrorsEnhancedResponseValidations]) {
        // @ts-expect-error
        const compiler = new AjvConstructor({
            // The fastify defaults, with the exception of removeAdditional and coerceTypes, which have been reversed
            removeAdditional: false,
            useDefaults: true,
            coerceTypes: false,
            allErrors: true
        });
        compiler.addSchema(Object.values(instance.getSchemas()));
        compiler.addKeyword('example');
        if (hasCustomizer) {
            configuration.responseValidatorCustomizer(compiler);
        }
        for (const [code, schema] of schemas) {
            validators[code] = compiler.compile(schema);
        }
    }
}
exports.compileResponseValidationSchema = compileResponseValidationSchema;

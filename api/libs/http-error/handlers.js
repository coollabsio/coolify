/* eslint-disable */
"use strict";   
Object.defineProperty(exports, "__esModule", { value: true });
exports.handleErrors = exports.handleValidationError = exports.handleNotFoundError = void 0;
const http_errors_enhanced_1 = require("http-errors-enhanced");
const interfaces_1 = require("./interfaces");
const utils_1 = require("./utils");
const validation_1 = require("./validation");
function handleNotFoundError(request, reply) {
    handleErrors(new http_errors_enhanced_1.NotFoundError('Not found.'), request, reply);
}
exports.handleNotFoundError = handleNotFoundError;
function handleValidationError(error, request) {
    /*
      As seen in https://github.com/fastify/fastify/blob/master/lib/validation.js
      the error.message will  always start with the relative section (params, querystring, headers, body)
      and fastify throws on first failing section.
    */
    const section = error.message.match(/^\w+/)[0];
    return new http_errors_enhanced_1.BadRequestError('One or more validations failed trying to process your request.', {
        failedValidations: validation_1.convertValidationErrors(section, Reflect.get(request, section), error.validation)
    });
}
exports.handleValidationError = handleValidationError;
function handleErrors(error, request, reply) {
    var _a, _b;
    // It is a generic error, handle it
    const code = error.code;
    if (!('statusCode' in error)) {
        if ('validation' in error && ((_a = request[interfaces_1.kHttpErrorsEnhancedConfiguration]) === null || _a === void 0 ? void 0 : _a.convertValidationErrors)) {
            // If it is a validation error, convert errors to human friendly format
            error = handleValidationError(error, request);
        }
        else if ((_b = request[interfaces_1.kHttpErrorsEnhancedConfiguration]) === null || _b === void 0 ? void 0 : _b.hideUnhandledErrors) {
            // It is requested to hide the error, just log it and then create a generic one
            request.log.error({ error: http_errors_enhanced_1.serializeError(error) });
            error = new http_errors_enhanced_1.InternalServerError('An error occurred trying to process your request.');
        }
        else {
            // Wrap in a HttpError, making the stack explicitily available
            error = new http_errors_enhanced_1.InternalServerError(http_errors_enhanced_1.serializeError(error));
            Object.defineProperty(error, 'stack', { enumerable: true });
        }
    }
    else if (code === 'INVALID_CONTENT_TYPE' || code === 'FST_ERR_CTP_INVALID_MEDIA_TYPE') {
        error = new http_errors_enhanced_1.UnsupportedMediaTypeError(utils_1.upperFirst(validation_1.validationMessagesFormatters.contentType()));
    }
    else if (code === 'FST_ERR_CTP_EMPTY_JSON_BODY') {
        error = new http_errors_enhanced_1.BadRequestError(utils_1.upperFirst(validation_1.validationMessagesFormatters.jsonEmpty()));
    }
    else if (code === 'MALFORMED_JSON' || error.message === 'Invalid JSON' || error.stack.includes('at JSON.parse')) {
        error = new http_errors_enhanced_1.BadRequestError(utils_1.upperFirst(validation_1.validationMessagesFormatters.json()));
    }
    // Get the status code
    let { statusCode, headers } = error;
    // Code outside HTTP range
    if (statusCode < 100 || statusCode > 599) {
        statusCode = http_errors_enhanced_1.INTERNAL_SERVER_ERROR;
    }
    // Create the body
    const body = {
        statusCode,
        error: http_errors_enhanced_1.messagesByCodes[statusCode],
        message: error.message
    };
    http_errors_enhanced_1.addAdditionalProperties(body, error);
    // Send the error back
    // eslint-disable-next-line @typescript-eslint/no-floating-promises
    reply
        .code(statusCode)
        .headers(headers !== null && headers !== void 0 ? headers : {})
        .type('application/json')
        .send(body);
}
exports.handleErrors = handleErrors;

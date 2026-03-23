<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$checkboxes = [];
$actions = [];
$confirmationText = "Confirm Deletion";
$confirmWithText = false;
$disableTwoStepConfirmation = false;
$confirmWithPassword = false;
$skipPasswordConfirmation = false;
$submitAction = "deleteFile('PRUEBA.zip')";
$dispatchAction = false;
$selectedActions = [];
$dispatchEvent = false;
$dispatchEventType = 'success';
$dispatchEventMessage = '';
$step1ButtonText = 'Delete';
$effectiveStep2ButtonText = 'Confirm';
$step3ButtonText = 'Confirm';

$out = <<<EOT
{
    modalOpen: false,
    step: 2,
    initialStep: 2,
    finalStep: 2,
    deleteText: '',
    password: '',
    actions: json_encode_here_actions,
    confirmationText: (function() {
        const textarea = document.createElement('textarea');
        textarea.innerHTML = json_encode_here_confirmationText;
        return textarea.value;
    })(),
    userConfirmationText: '',
    confirmWithText: json_encode_here_confirmWithText,
    confirmWithPassword: json_encode_here_confirmWithPassword,
    submitAction: json_encode_here_submitAction,
    dispatchAction: json_encode_here_dispatchAction,
    passwordError: '',
    selectedActions: json_encode_here_selectedActions,
    dispatchEvent: json_encode_here_dispatchEvent,
    dispatchEventType: json_encode_here_dispatchEventType,
    dispatchEventMessage: json_encode_here_dispatchEventMessage,
    disableTwoStepConfirmation: json_encode_here_disableTwoStepConfirmation,
    skipPasswordConfirmation: json_encode_here_skipPasswordConfirmation,
    resetModal() {
        this.step = this.initialStep;
        this.deleteText = '';
        this.password = '';
        this.userConfirmationText = '';
        const checkboxes = json_encode_here_checkboxes;
        if (checkboxes && checkboxes.length !== 0) {
            this.selectedActions = checkboxes.filter(function(checkbox) {
                try {
                    return \$wire.get(checkbox.id) === true;
                } catch (e) {
                    return false;
                }
            }).map(function(checkbox) { return checkbox.id; });
        } else {
            this.selectedActions = [];
        }
        \$wire.\$refresh();
    },
    step1ButtonText: json_encode_here_step1ButtonText,
    step2ButtonText: json_encode_here_step2ButtonText,
    step3ButtonText: json_encode_here_step3ButtonText,
    validatePassword() {
        if (this.confirmWithPassword && !this.password) {
            return 'Password is required.';
        }
        return '';
    },
    submitForm() {
        if (this.confirmWithPassword) {
            this.passwordError = this.validatePassword();
            if (this.passwordError) {
                return Promise.resolve(this.passwordError);
            }
        }
        if (this.dispatchAction) {
            \$wire.dispatch(this.submitAction);
            return Promise.resolve(true);
        }

        const methodName = this.submitAction.split('(')[0];
        const paramsMatch = this.submitAction.match(/\((.*?)\)/);
        const params = paramsMatch && paramsMatch[1] 
            ? paramsMatch[1].split(',').map(function(param) {
                let p = param.trim();
                if ((p.startsWith("'") && p.endsWith("'")) || (p.startsWith('\x22') && p.endsWith('\x22'))) {
                    p = p.slice(1, -1);
                }
                return p === 'true' ? true : p === 'false' ? false : p;
            }) 
            : [];

        params.push(this.confirmWithPassword ? this.password : '');
        if (this.selectedActions.length !== 0) {
            params.push(this.selectedActions);
        }

        try {
            const resultPromise = \$wire[methodName](...params);
            if (resultPromise && typeof resultPromise.then === 'function') {
                return resultPromise.then(function(result) {
                    if (typeof result === 'string') {
                        return result; 
                    } else if (result === false) {
                        return 'Action failed.';
                    }
                    return true; 
                }).catch(function(err) {
                    return err.message || 'An error occurred';
                });
            } else {
                return Promise.resolve(true);
            }
        } catch (err) {
            return Promise.resolve(err.message || 'An error occurred');
        }
    },
    toggleAction(id) {
        const index = this.selectedActions.indexOf(id);
        if (index !== -1) {
            this.selectedActions.splice(index, 1);
        } else {
            this.selectedActions.push(id);
        }
    }
}
EOT;

$out = str_replace('json_encode_here_actions', htmlspecialchars(json_encode($actions), ENT_QUOTES, 'UTF-8', false), $out);
$out = str_replace('json_encode_here_confirmationText', htmlspecialchars(json_encode($confirmationText), ENT_QUOTES, 'UTF-8', false), $out);
$out = str_replace('json_encode_here_confirmWithText', htmlspecialchars(json_encode($confirmWithText), ENT_QUOTES, 'UTF-8', false), $out);
$out = str_replace('json_encode_here_confirmWithPassword', htmlspecialchars(json_encode($confirmWithPassword), ENT_QUOTES, 'UTF-8', false), $out);
$out = str_replace('json_encode_here_submitAction', htmlspecialchars(json_encode($submitAction), ENT_QUOTES, 'UTF-8', false), $out);
$out = str_replace('json_encode_here_dispatchAction', htmlspecialchars(json_encode($dispatchAction), ENT_QUOTES, 'UTF-8', false), $out);
$out = str_replace('json_encode_here_selectedActions', htmlspecialchars(json_encode($selectedActions), ENT_QUOTES, 'UTF-8', false), $out);
$out = str_replace('json_encode_here_dispatchEvent', htmlspecialchars(json_encode($dispatchEvent), ENT_QUOTES, 'UTF-8', false), $out);
$out = str_replace('json_encode_here_dispatchEventType', htmlspecialchars(json_encode($dispatchEventType), ENT_QUOTES, 'UTF-8', false), $out);
$out = str_replace('json_encode_here_dispatchEventMessage', htmlspecialchars(json_encode($dispatchEventMessage), ENT_QUOTES, 'UTF-8', false), $out);
$out = str_replace('json_encode_here_disableTwoStepConfirmation', htmlspecialchars(json_encode($disableTwoStepConfirmation), ENT_QUOTES, 'UTF-8', false), $out);
$out = str_replace('json_encode_here_skipPasswordConfirmation', htmlspecialchars(json_encode($skipPasswordConfirmation), ENT_QUOTES, 'UTF-8', false), $out);
$out = str_replace('json_encode_here_checkboxes', htmlspecialchars(json_encode($checkboxes), ENT_QUOTES, 'UTF-8', false), $out);
$out = str_replace('json_encode_here_step1ButtonText', htmlspecialchars(json_encode($step1ButtonText), ENT_QUOTES, 'UTF-8', false), $out);
$out = str_replace('json_encode_here_step2ButtonText', htmlspecialchars(json_encode($effectiveStep2ButtonText), ENT_QUOTES, 'UTF-8', false), $out);
$out = str_replace('json_encode_here_step3ButtonText', htmlspecialchars(json_encode($step3ButtonText), ENT_QUOTES, 'UTF-8', false), $out);

echo "<div x-data=\"" . $out . "\"></div>\n";

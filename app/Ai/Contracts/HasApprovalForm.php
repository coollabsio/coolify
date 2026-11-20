<?php

namespace App\Ai\Contracts;

use App\Ai\Ui\ApprovalForm;

interface HasApprovalForm
{
    /**
     * Build the editable approval form for a pending tool call, pre-filled from
     * the model's proposed arguments. Authored by the tool (trusted), never by
     * the model.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function approvalForm(array $arguments): ApprovalForm;
}

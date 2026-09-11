<?php

namespace App\Exceptions;

class ServiceTemplateNotFoundException extends ResourceCreationException
{
    /** @param array<int, string> $validServiceTypes */
    public function __construct(array $validServiceTypes)
    {
        parent::__construct('Service not found.', ['valid_service_types' => $validServiceTypes]);
    }
}

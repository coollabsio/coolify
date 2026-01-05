<?php

namespace App\Traits;

trait HasSafeStringAttribute
{
    /**
     * Set the name attribute - strip any HTML tags for safety
     */
    public function setNameAttribute($value)
    {
        $sanitized = strip_tags($value);
        $this->attributes['name'] = $this->customizeName($sanitized);
    }

    protected function customizeName($value)
    {
        return $value; // Default: no customization
    }

    public function setDescriptionAttribute($value)
    {
        $this->attributes['description'] = strip_tags($value);
    }
}

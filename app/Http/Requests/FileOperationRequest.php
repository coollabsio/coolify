<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FileOperationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $rules = [
            'path' => 'required|string|max:1000',
        ];

        // Add specific rules based on the route action
        $action = $this->route()->getActionMethod();

        switch ($action) {
            case 'uploadFile':
                $rules['file'] = 'required|file|max:102400'; // 100MB max
                $rules['permissions'] = 'nullable|string|regex:/^[0-7]{3,4}$/';
                break;

            case 'createDirectory':
                $rules['permissions'] = 'nullable|string|regex:/^[0-7]{3,4}$/';
                break;

            case 'updatePermissions':
                $rules['permissions'] = 'required|string|regex:/^[0-7]{3,4}$/';
                $rules['recursive'] = 'nullable|boolean';
                break;

            case 'deleteItem':
                $rules['is_directory'] = 'nullable|boolean';
                break;
        }

        return $rules;
    }

    /**
     * Get custom error messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'path.required' => 'File path is required.',
            'path.max' => 'File path is too long.',
            'file.required' => 'Please select a file to upload.',
            'file.max' => 'File size cannot exceed 100MB.',
            'permissions.regex' => 'Permissions must be in octal format (e.g., 755, 644).',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Sanitize path
        if ($this->has('path')) {
            $path = $this->input('path');
            $path = str_replace(['../', '..\\'], '', $path);
            $this->merge(['path' => $path]);
        }
    }
}

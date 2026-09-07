<?php

namespace App\Http\Requests\Workspace;

use Illuminate\Foundation\Http\FormRequest;

class StoreWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'domain' => ['required_without:name', 'nullable', 'string', 'max:255'],
            'name' => ['required_without:domain', 'nullable', 'string', 'max:255'],
        ];
    }
}

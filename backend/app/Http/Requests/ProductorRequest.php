<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Application\Productores\ProductorValidation;
use Illuminate\Foundation\Http\FormRequest;

class ProductorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ProductorValidation::rules($this->route('productor') ? (int) $this->route('productor') : null, $this->isMethod('patch'));
    }

    public function messages(): array
    {
        return ProductorValidation::messages();
    }
}

<?php

namespace App\Http\Requests;

use App\Helpers\Helper;
use Illuminate\Support\Facades\Gate;

class UpdateComponentRequest extends ImageUploadRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', $this->component);
    }

    public function prepareForValidation(): void
    {
        if ($this->filled('purchase_cost') && ! is_float($this->input('purchase_cost')) && preg_match('/^[\d.,]+$/', (string) $this->input('purchase_cost'))) {
            $this->merge(['purchase_cost' => Helper::ParseCurrency($this->input('purchase_cost'))]);
        }
    }

    public function rules(): array
    {
        // qty is no longer submitted via the edit form — adjust-quantity
        // modal handles all post-create qty changes so each change is a
        // separate action_log entry. No qty rule needed here.
        return parent::rules();
    }

    public function response(array $errors)
    {
        return $this->redirector->back()->withInput()->withErrors($errors, $this->errorBag);
    }
}

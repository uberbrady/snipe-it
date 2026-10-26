<?php

namespace App\Http\Requests;

use App\Models\Labels\CustomLabelFonts;
use App\Rules\LabelGeometryRules;
use App\Rules\CustomLabelContentRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;
class CustomLabelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isTape = $this->input('type') === 'tape';

        return array_merge(
            $this->commonRules(),
            CustomLabelContentRules::shared(),
            $isTape ? CustomLabelContentRules::tape() : [],
            $isTape ? $this->tapeRules() : $this->sheetRules(),
        );
    }

    private function commonRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['sheet', 'tape'])],
            'template' => ['required', 'string'],
            'content' => ['required', 'array'],
            'supports' => ['required', 'array'],
            'content.tag_font' => ['nullable', 'string', Rule::in(CustomLabelFonts::ALLOWED)],
            'content.title_font' => ['nullable', 'string', Rule::in(CustomLabelFonts::ALLOWED)],
            'content.field_label_font' => ['nullable', 'string', Rule::in(CustomLabelFonts::ALLOWED)],
            'content.field_value_font' => ['nullable', 'string', Rule::in(CustomLabelFonts::ALLOWED)],
            'content.title_offset_x' => ['required', 'numeric'],
        ];
    }

    private function tapeRules(): array
    {
        return [
            'dimensions' => ['required', 'array'],
            'dimensions.width' => ['required', 'numeric', 'gt:0'],
            'dimensions.height' => ['required', 'numeric', 'gt:0'],
            'dimensions.label_gap' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    private function sheetRules(): array
    {
        return [
                'page' => ['required', 'array'],
                'grid' => ['required', 'array'],
                'label' => ['required', 'array'],
            ] + LabelGeometryRules::sheet();
    }

    protected function failedValidation(Validator $validator)
    {
        logger()->debug('Custom label validation failed', [
            'errors' => $validator->errors()->toArray(),
            'type' => $this->input('type'),
            'submitted_alignment' => $this->input('content.barcode2D_h_align'),
        ]);

        throw new ValidationException($validator);
    }
}
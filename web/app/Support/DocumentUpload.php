<?php

namespace App\Support;

/**
 * Shared rule for identity and business documents (Seller, Logistics, Rider).
 * JPG, PNG or PDF only, checked by file content (mimes) and by extension, so a
 * renamed .exe is refused; at most 5,120 KB (exactly 5 MB is accepted).
 * Mirrors checkDocFile() in resources/js/registration-validation.js.
 */
class DocumentUpload
{
    public const MAX_KB = 5120;

    /** @return array<int, string> */
    public static function rules(bool $required = true): array
    {
        return [
            $required ? 'required' : 'nullable',
            'file',
            'mimes:jpg,jpeg,png,pdf',
            'extensions:jpg,jpeg,png,pdf',
            'max:'.self::MAX_KB,
        ];
    }

    /** One message naming the accepted types and the size limit, whichever check fails. */
    public static function message(string $label): string
    {
        return $label.' must be a JPG, PNG or PDF file no larger than 5 MB.';
    }

    /** @return array<string, string> */
    public static function messages(string $field, string $label): array
    {
        $message = self::message($label);

        return [
            $field.'.file' => $message,
            $field.'.uploaded' => $message,
            $field.'.mimes' => $message,
            $field.'.extensions' => $message,
            $field.'.max' => $message,
        ];
    }
}

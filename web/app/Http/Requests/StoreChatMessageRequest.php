<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One rule set for both sides of a chat. Conversation access is checked by
 * the controllers through ConversationPolicy.
 */
class StoreChatMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'conversation_id' => ['required', 'exists:conversations,id'],
            // A photo may be sent without a caption; otherwise text is required.
            'body' => ['nullable', 'required_without:image', 'string', 'max:1000'],
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required_without' => 'Enter a message or attach a photo.',
            'body.max' => 'Messages may not be more than 1,000 characters.',
            'image.image' => 'Photos must be JPG or PNG files.',
            'image.mimes' => 'Photos must be JPG or PNG files.',
            'image.max' => 'Photos may not be larger than 5 MB.',
        ];
    }
}

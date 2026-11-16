<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Shared\Conversation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/** Backwards-compatible Chat links open the existing Messages system. */
class ChatController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->route('buyer.messages.index');
    }

    public function show(string $id): RedirectResponse
    {
        $conversation = Conversation::query()->findOrFail($id);
        Gate::forUser(auth('buyer')->user())->authorize('view', $conversation);

        return redirect()->route('buyer.messages.show', $conversation);
    }
}

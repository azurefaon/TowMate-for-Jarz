<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class ChatController extends Controller
{
    public function index()
    {
        $chatModel = 'App\\Models\\Chat';

        $chats = class_exists($chatModel)
            ? $chatModel::with('driver')
            ->where('customer_id', Auth::id())
            ->latest()
            ->get()
            : collect();

        return view('customer.pages.chat', compact('chats'));
    }

    public function show($id)
    {
        $chatModel = 'App\\Models\\Chat';

        if (!class_exists($chatModel)) {
            return view('customer.pages.chat', [
                'chats' => collect(),
                'activeChat' => null,
            ]);
        }

        $activeChat = $chatModel::with(['messages', 'driver'])
            ->where('customer_id', Auth::id())
            ->findOrFail($id);

        $chats = $chatModel::with('driver')
            ->where('customer_id', Auth::id())
            ->latest()
            ->get();

        return view('customer.pages.chat', compact('chats', 'activeChat'));
    }
}

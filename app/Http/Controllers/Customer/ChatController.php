<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function index()
    {
        $chats = Chat::where('customer_id', auth()->id())->get();

        return view('customer.pages.chat', compact('chats'));
    }

    public function show($id)
    {
        $activeChat = Chat::with('messages', 'driver')
            ->findOrFail($id);

        $chats = Chat::where('customer_id', auth()->id())->get();

        return view('customer.pages.chat', compact('chats', 'activeChat'));
    }
}

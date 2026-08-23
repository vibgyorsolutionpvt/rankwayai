<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EditorMediaController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ]);

        $file = $data['image'];
        $path = $file->storeAs(
            'editor/'.$request->user()->id,
            Str::uuid()->toString().'.'.$file->getClientOriginalExtension(),
            'public',
        );

        return response()->json([
            'url' => '/storage/'.$path,
        ]);
    }
}

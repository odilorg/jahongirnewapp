<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SysInstruction;
use Illuminate\Http\Request;

class SysInstructionController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'sys_instruction' => 'required|string|max:255'
        ]);

        $instruction = SysInstruction::create($validated);

        return response()->json([
            'message' => 'System instruction created',
            'data' => $instruction
        ], 201);
    }
}
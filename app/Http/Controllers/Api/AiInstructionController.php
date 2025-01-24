<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiInstruction;
use Illuminate\Http\Request;

class AiInstructionController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index()
    {
        return AiInstruction::all();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'instruction' => 'required|string',
            // Add other validation rules
        ]);

        return AiInstruction::create($validated);
    }

    public function show(AiInstruction $aiInstruction)
    {
        return $aiInstruction;
    }

    public function update(Request $request, AiInstruction $aiInstruction)
    {
        $validated = $request->validate([
            //'title' => 'sometimes|string|max:255',
            'instruction' => 'sometimes|string',
            // Add other validation rules
        ]);

        $aiInstruction->update($validated);
        return $aiInstruction;
    }

    public function destroy(AiInstruction $aiInstruction)
    {
        $aiInstruction->delete();
        return response(null, 204);
    }
}
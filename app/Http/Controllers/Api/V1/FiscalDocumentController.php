<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FiscalDocument;
use App\Models\Sale;
use Illuminate\Http\Request;

class FiscalDocumentController extends Controller
{
    public function store(Request $request, Sale $sale)
    {
        $data = $request->validate(['model' => 'required|in:NFE,NFC-e']);
        $document = FiscalDocument::create(['sale_id' => $sale->id, 'model' => $data['model'], 'status' => 'pending', 'message' => 'Aguardando configuração do provedor fiscal e credenciais do emitente.']);
        return response()->json($document, 202);
    }
}

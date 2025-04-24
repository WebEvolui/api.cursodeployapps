<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class TidalController extends Controller
{
    public function getTidal(Request $request, $city)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $token = PersonalAccessToken::findToken($request->token);

        $url = "https://nominatim.openstreetmap.org/search?q=" . urlencode($city) . "&format=json&addressdetails=1";
        $options = [
            "http" => [
                "header" => "User-Agent: TabuadasMaresWebEvolui/1.0\r\n"
            ]
        ];
        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);
        $data = json_decode($response, true);

        $latitude = $data[0]['lat'] ?? null;
        $longitude = $data[0]['lon'] ?? null;

        if (!$latitude || !$longitude) {
            return response()->json([
                'error' => 'Location not found',
            ], 404);
        }

        if (!$token) {
            return response()->json(['error' => 'Você não tem permissão para acessar essa API'], 401);
        }

        $token = env('WORLDTIDES_API_KEY');
        $apiUrl = "https://www.worldtides.info/api/v3?extremes&date=2025-04-18&lat=$latitude&lon=$longitude&days=7&key=$token";

        $response = file_get_contents($apiUrl);
        $data = json_decode($response, true);

        return response()->json($data);
    }
}

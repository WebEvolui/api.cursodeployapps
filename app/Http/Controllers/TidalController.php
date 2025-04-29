<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class TidalController extends Controller
{
    public function getTidal(Request $request, $city)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $token = PersonalAccessToken::findToken($request->token);
        if (!$token) {
            return response()->json(['error' => 'Você não tem permissão para acessar essa API'], 401);
        }

        $cacheKey = Str::of(urldecode($city))->slug('_')->toString();

        if (Cache::has($cacheKey)) {
            $cachedData = Cache::get($cacheKey);
            return response()->json($cachedData);
        }

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

        $token = env('WORLDTIDES_API_KEY');
        $date = date('Y-m-d');
        $apiUrl = "https://www.worldtides.info/api/v3?heights&extremes&date=$date&lat=$latitude&lon=$longitude&days=1&key=$token&datum=CD&localtime&timezone";

        $response = file_get_contents($apiUrl, false);
        $data = json_decode($response, true);

        $array = [
            'alturas' => [],
            'extremos' => [],
        ];

        // Gerar os índices desejados dinamicamente
        $indices = range(0, 48, 6);

        // Filtrar as alturas com base nos índices
        $array['alturas'] = array_values(array_filter($data['heights'], function ($value, $key) use ($indices) {
            return in_array($key, $indices);
        }, ARRAY_FILTER_USE_BOTH));

        // Substituir as alturas próximas pelos extremos
        foreach ($data['extremes'] as $extreme) {
            $closestIndex = null;
            $closestDiff = PHP_INT_MAX;

            foreach ($array['alturas'] as $index => $altura) {
                $diff = abs($altura['dt'] - $extreme['dt']);
                if ($diff < $closestDiff) {
                    $closestDiff = $diff;
                    $closestIndex = $index;
                }
            }

            // Substituir a altura mais próxima pelo extremo
            if ($closestIndex !== null) {
                $array['alturas'][$closestIndex] = $extreme;
            }
        }

        // Adicionar os extremos ao array final
        $array['extremos'] = $data['extremes'];

        Cache::put($cacheKey, $array, now()->endOfDay());

        return response()->json($array);
    }
}

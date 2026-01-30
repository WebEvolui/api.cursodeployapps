<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class LocationController extends Controller
{
    public function getLocation(Request $request)
    {
        $request->validate([
            'latitude'  => 'required|numeric',
            'longitude' => 'required|numeric',
        ]);

        $response = Http::timeout(20)
            ->retry(2, 200)
            ->withHeaders([
                'User-Agent' => 'TabuadasMaresWebEvolui/1.0',
            ])
            ->get('https://nominatim.openstreetmap.org/reverse', [
                'lat'    => $request->latitude,
                'lon'    => $request->longitude,
                'format' => 'json',
            ]);

        if (! $response->successful()) {
            return response()->json([
                'error'  => 'Failed to retrieve location data',
                'status' => $response->status(),
            ], 502);
        }

        $data = $response->json();

        if (! is_array($data)) {
            return response()->json([
                'error' => 'Invalid response from location service',
            ], 502);
        }

        $address = $data['address'] ?? [];

        $city = $address['city']
            ?? $address['town']
            ?? $address['village']
            ?? null;

        if (! $city) {
            return response()->json([
                'error' => 'Location not found',
            ], 404);
        }

        $state = $address['state']
            ?? $address['region']
            ?? $address['province']
            ?? $address['state_district']
            ?? null;

        $country = $address['country'] ?? null;

        $parts = array_values(array_filter([$city, $state, $country]));

        $localizacao = implode(', ', $parts);
        $cityParam   = urlencode(implode(',', $parts));

        return response()->json([
            'city' => $cityParam,
            'localizacao' => $localizacao,
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function getLocation(Request $request)
    {
        // Validate the request
        $request->validate([
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
        ]);

        $latitude = $request->input('latitude');
        $longitude = $request->input('longitude');

        $url = "https://nominatim.openstreetmap.org/reverse?lat={$latitude}&lon={$longitude}&format=json";
        $options = [
            "http" => [
                "header" => "User-Agent: TabuadasMaresWebEvolui/1.0\r\n"
            ]
        ];
        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);
        $data = json_decode($response, true);

        $city = null;

        if (isset($data['address']['city'])) {
            $city = $data['address']['city'];
        } elseif (isset($data['address']['town'])) {
            $city = $data['address']['town'];
        } elseif (isset($data['address']['village'])) {
            $city = $data['address']['village'];
        } else {
            return response()->json([
                'error' => 'Location not found',
            ], 404);
        }

        $localizacao = $city . ", " . $data['address']['state'] . ", " . $data['address']['country'];
        $city = $city . "," . $data['address']['state'] . "," . $data['address']['country'];
        $city = urlencode($city);

        return response()->json([
            'city' => $city,
            'localizacao' => $localizacao,
        ]);
    }
}

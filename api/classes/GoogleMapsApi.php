<?php
    class GoogleMapsApi {
        private $db;
        private $user_info;
        private $parameters;
        
        
        function __construct($db, $user_info, $parameters) {
            $this->db = $db;
            $this->user_info = json_decode($user_info, true);
            $this->parameters = $parameters;
        }


        public function getAddress() {
            // 1) Validazioni di base
            $address = isset($this->parameters->address) ? trim((string)$this->parameters->address) : '';
            if ($address === '') {
                return [
                    'success' => 0,
                    'message' => "Missing fields: 'address' is required.",
                    'error_code' => 'getAddress.002',
                    'status' => 400
                ];
            }

            // Recupero API key: dai parametri o da variabile d'ambiente
            $apiKey = null;
            $basepath = dirname(dirname(__FILE__));
            $settings = json_decode(file_get_contents($basepath . "/../config/settings.json"), true);
            if(isset($settings['api_keys']['google_maps_api_key'])) {
                $apiKey = $settings['api_keys']['google_maps_api_key'];
            }

            if (!$apiKey) {
                return [
                    'success' => 0,
                    'message' => "Missing fields: Google Maps API key not provided.",
                    'error_code' => 'getAddress.001',
                    'status' => 400
                ];
            }

            // 2) Costruzione URL Geocoding API
            $language = isset($this->parameters->language) && $this->parameters->language ? (string)$this->parameters->language : 'it';
            $country  = isset($this->parameters->country)  && $this->parameters->country  ? (string)$this->parameters->country  : null;

            $query = [
                'address'  => $address,
                'language' => $language,
                'key'      => $apiKey,
            ];
            // Filtro per paese (opzionale)
            if ($country) {
                // Esempio: components=country:IT
                $query['components'] = 'country:' . $country;
            }

            $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query($query);

            // 3) Chiamata HTTP
            $http = $this->httpGet($url);
            if ($http['error']) {
                return [
                    'success' => 0,
                    'message' => "HTTP request error: " . $http['error'],
                    'error_code' => 'getAddress.003',
                    'status' => 502
                ];
            }

            $body = json_decode($http['body'], true);
            if (!is_array($body) || !isset($body['status'])) {
                return [
                    'success' => 0,
                    'message' => "Malformed response from Google Geocoding API.",
                    'error_code' => 'getAddress.004',
                    'status' => 502
                ];
            }

            // 4) Gestione status della Geocoding API
            if ($body['status'] !== 'OK' || empty($body['results'])) {
                // Possibili status: ZERO_RESULTS, OVER_DAILY_LIMIT, OVER_QUERY_LIMIT, REQUEST_DENIED, INVALID_REQUEST, UNKNOWN_ERROR
                $msg = isset($body['error_message']) ? $body['status'] . ': ' . $body['error_message'] : $body['status'];
                $httpStatus = ($body['status'] === 'ZERO_RESULTS') ? 404 : 502;

                return [
                    'success' => 0,
                    'message' => "Geocoding failed: " . $msg,
                    'error_code' => 'getAddress.005',
                    'status' => $httpStatus
                ];
            }

            // 5) Parsing del primo risultato
            $result = $body['results'][0];

            $formattedAddress = isset($result['formatted_address']) ? $result['formatted_address'] : '';
            $placeId          = isset($result['place_id']) ? $result['place_id'] : null;

            $lat = isset($result['geometry']['location']['lat']) ? (float)$result['geometry']['location']['lat'] : null;
            $lng = isset($result['geometry']['location']['lng']) ? (float)$result['geometry']['location']['lng'] : null;

            $components = isset($result['address_components']) ? $result['address_components'] : [];

            // Estrazione componenti con fallback intelligenti (locality può essere locality / postal_town / administrative_area_level_3)
            $route         = $this->pickComponent($components, ['route']);
            $streetNumber  = $this->pickComponent($components, ['street_number']);
            $postalCode    = $this->pickComponent($components, ['postal_code']);
            $countryLong   = $this->pickComponent($components, ['country'], false); // long_name
            $countryShort  = $this->pickComponent($components, ['country'], true);  // short_name
            $region        = $this->pickComponent($components, ['administrative_area_level_1'], false);
            $provinceShort = $this->pickComponent($components, ['administrative_area_level_2'], true);  // es. MI, TO...
            $locality      = $this->pickComponent($components, ['locality', 'postal_town', 'administrative_area_level_3', 'sublocality', 'neighborhood'], false);

            // 6) Output normalizzato (compatibile con quanto usi nel front-end)
            $data = [
                'formattedAddress' => $formattedAddress,
                'placeId'          => $placeId,
                'latitude'         => $lat,
                'longitude'        => $lng,
                'route'            => $route,
                'locality'         => $locality,
                'province'         => $provinceShort,
                'region'           => $region,
                'country'          => $countryLong,
                'countryCode'      => $countryShort,
                'postalCode'       => $postalCode,
                'streetNumber'     => $streetNumber,
            ];

            // 7) Ritorno
            $response = [
                'success' => 1,
                'message' => 'OK',
                'status'  => 200,
                'data'    => $data
            ];

            // Se vuoi includere il payload completo per debug, passa parameters->debug = true
            if (isset($this->parameters->debug) && $this->parameters->debug) {
                $response['debug'] = [
                    'raw' => $body
                ];
            }

            return $response;
        }

        /**
         * Effettua una GET con cURL e ritorna ['body' => string|null, 'error' => string|null]
         */
        private function httpGet(string $url): array {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json'
                ],
            ]);
            $body = curl_exec($ch);
            $err  = curl_error($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($err) {
                return ['body' => null, 'error' => $err];
            }
            if ($code < 200 || $code >= 300) {
                return ['body' => null, 'error' => 'HTTP ' . $code];
            }
            return ['body' => $body, 'error' => null];
        }

        /**
         * Estrae un componente dagli address_components di Google dando una lista di possibili 'types'.
         * Se $short = true, ritorna short_name; altrimenti long_name.
         */
        private function pickComponent(array $addressComponents, array $typesWanted, bool $short = false): ?string {
            foreach ($typesWanted as $tWanted) {
                foreach ($addressComponents as $comp) {
                    if (!isset($comp['types']) || !is_array($comp['types'])) continue;
                    // i 'types' contengono più valori; basta che uno coincida
                    if (in_array($tWanted, $comp['types'], true)) {
                        return isset($comp[$short ? 'short_name' : 'long_name'])
                            ? (string)$comp[$short ? 'short_name' : 'long_name']
                            : null;
                    }
                }
            }
            return null;
        }



        public function getSuggestions() {
            // 1) Validazioni
            $input = isset($this->parameters->input) ? trim((string)$this->parameters->input) : '';
            if ($input === '') {
                return [
                    'success' => 0,
                    'message' => "Missing fields: 'input' is required.",
                    'error_code' => 'getSuggestions.002',
                    'status' => 400
                ];
            }

            // 2) API key: da parametri o ENV
            $apiKey = null;
            $basepath = dirname(dirname(__FILE__));
            $settings = json_decode(file_get_contents($basepath . "/../config/settings.json"), true);
            if(isset($settings['api_keys']['google_maps_api_key'])) {
                $apiKey = $settings['api_keys']['google_maps_api_key'];
            }

            if (!$apiKey) {
                return [
                    'success' => 0,
                    'message' => "Missing fields: Google Maps API key not provided.",
                    'error_code' => 'getSuggestions.001',
                    'status' => 400
                ];
            }

            // 3) Parametri opzionali
            $language = isset($this->parameters->language) && $this->parameters->language ? (string)$this->parameters->language : 'it';
            $country  = isset($this->parameters->country)  && $this->parameters->country  ? (string)$this->parameters->country  : null;
            $sessionToken = isset($this->parameters->sessionToken) && $this->parameters->sessionToken
                ? (string)$this->parameters->sessionToken
                : bin2hex(random_bytes(16)); // generato se non fornito

            // Bias geografico opzionale
            $lat = isset($this->parameters->latitude)  ? (float)$this->parameters->latitude  : null;
            $lng = isset($this->parameters->longitude) ? (float)$this->parameters->longitude : null;
            $radius = isset($this->parameters->radius) ? (int)$this->parameters->radius : null; // metri

            // 'types' opzionale (es. 'address', 'geocode', 'establishment')
            $types = isset($this->parameters->types) && $this->parameters->types ? (string)$this->parameters->types : null;

            // 4) Costruzione query
            $query = [
                'input'        => $input,
                'language'     => $language,
                'key'          => $apiKey,
                'sessiontoken' => $sessionToken,
            ];
            if ($country) {
                $query['components'] = 'country:' . $country;
            }
            if ($lat !== null && $lng !== null) {
                $query['location'] = $lat . ',' . $lng; // bias centrale
                if ($radius !== null && $radius > 0) {
                    $query['radius'] = $radius; // raggio in metri
                }
            }
            if ($types) {
                $query['types'] = $types;
            }

            $url = 'https://maps.googleapis.com/maps/api/place/autocomplete/json?' . http_build_query($query);

            // 5) Chiamata HTTP
            $http = $this->httpGet($url);
            if ($http['error']) {
                return [
                    'success' => 0,
                    'message' => "HTTP request error: " . $http['error'],
                    'error_code' => 'getSuggestions.003',
                    'status' => 502
                ];
            }

            $body = json_decode($http['body'], true);
            if (!is_array($body) || !isset($body['status'])) {
                return [
                    'success' => 0,
                    'message' => "Malformed response from Google Places Autocomplete API.",
                    'error_code' => 'getSuggestions.004',
                    'status' => 502
                ];
            }

            // 6) Gestione status
            $status = $body['status'];
            if ($status === 'OK') {
                $predictions = isset($body['predictions']) && is_array($body['predictions']) ? $body['predictions'] : [];

                // Normalizzazione (camelCase e campi principali)
                $normalized = array_map(function ($p) {
                    return [
                        'description' => $p['description'] ?? '',
                        'placeId' => $p['place_id'] ?? null,
                        'structured_formatting' => [
                            'main_text' => $p['structured_formatting']['main_text'] ?? null,
                            'secondary_text' => $p['structured_formatting']['secondary_text'] ?? null,
                        ],
                        'types' => $p['types'] ?? [],
                        'terms' => $p['terms'] ?? [],
                        'matched_substrings' => $p['matched_substrings'] ?? [],
                    ];
                }, $predictions);

                $response = [
                    'success' => 1,
                    'message' => 'OK',
                    'status'  => 200,
                    'data'    => [
                        'predictions'  => $normalized,
                        'sessionToken' => $sessionToken // utile al frontend per riuso
                    ],
                ];

                if (isset($this->parameters->debug) && $this->parameters->debug) {
                    $response['debug'] = ['raw' => $body];
                }

                return $response;
            }

            if ($status === 'ZERO_RESULTS') {
                // Non è un errore: ritorniamo 200 con lista vuota
                return [
                    'success' => 1,
                    'message' => 'OK',
                    'status'  => 200,
                    'data'    => [
                        'predictions'  => [],
                        'sessionToken' => $sessionToken
                    ]
                ];
            }

            // Altri status: OVER_QUERY_LIMIT, REQUEST_DENIED, INVALID_REQUEST, UNKNOWN_ERROR
            $msg = isset($body['error_message']) ? $status . ': ' . $body['error_message'] : $status;
            return [
                'success' => 0,
                'message' => "Places Autocomplete failed: " . $msg,
                'error_code' => 'getSuggestions.005',
                'status' => ($status === 'REQUEST_DENIED' || $status === 'INVALID_REQUEST') ? 400 : 502
            ];
        }

    }
?>
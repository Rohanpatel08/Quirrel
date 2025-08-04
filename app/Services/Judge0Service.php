<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class Judge0Service
{
    private $client;
    private $baseUrl;
    private $mysqlLanguageId;

    public function __construct()
    {
        $this->client = new Client();
        $this->baseUrl = config('app.judge0_api_url', 'http://localhost:2358');
        $this->mysqlLanguageId = config('app.judge0_mysql_language_id', 82);
    }

    public function executeQuery(string $sqlQuery): array
    {
        try {
            // dd($this->mysqlLanguageId);
            // Submit query to Judge0
            $response = $this->client->post("{$this->baseUrl}/submissions/?base64_encoded=false&wait=true", [
                'json' => [
                    'source_code' => $sqlQuery,
                    'language_id' => $this->mysqlLanguageId,
                ],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-RapidAPI-Key' => env('JUDGE0_API_KEY'),
                    'X-RapidAPI-Host' => env('JUDGE0_API_HOST'),
                ]
            ]);
            $submissionData = json_decode($response->getBody(), true);
            $token = $submissionData['token'];

            // Poll for results
            $maxAttempts = 30;
            $attempts = 0;

            while ($attempts < $maxAttempts) {
                sleep(1);

                $resultResponse = $this->client->get("{$this->baseUrl}/submissions/{$token}");
                $result = json_decode($resultResponse->getBody(), true);

                // Check if execution is complete
                if ($result['status']['id'] > 2) {
                    return [
                        'success' => true,
                        'stdout' => $result['stdout'] ? base64_decode($result['stdout']) : '',
                        'stderr' => $result['stderr'] ? base64_decode($result['stderr']) : '',
                        'compile_output' => $result['compile_output'] ? base64_decode($result['compile_output']) : '',
                        'status' => $result['status'],
                        'execution_time' => $result['time'],
                        'memory_usage' => $result['memory'],
                    ];
                }

                $attempts++;
            }

            return [
                'success' => false,
                'error' => 'Execution timeout',
                'stdout' => '',
                'stderr' => '',
            ];
        } catch (RequestException $e) {
            Log::error('Judge0 API Error: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => 'Judge0 API Error: ' . $e->getMessage(),
                'stdout' => '',
                'stderr' => '',
            ];
        }
    }

    public function getLanguages(): array
    {
        try {
            $response = $this->client->get("{$this->baseUrl}/languages");
            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            Log::error('Judge0 Languages Error: ' . $e->getMessage());
            return [];
        }
    }

    public function checkConnection(): bool
    {
        try {
            $response = $this->client->get("{$this->baseUrl}/languages", [
                'headers' => [
                    'X-RapidAPI-Key' => env('JUDGE0_API_KEY'),
                    'X-RapidAPI-Host' => env('JUDGE0_API_HOST'),
                ],
            ]);
            return $response->getStatusCode() === 200;
        } catch (RequestException $e) {
            return false;
        }
    }
}

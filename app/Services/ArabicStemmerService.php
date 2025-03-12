<?php

namespace App\Services;

use Phalcon\Di\Injectable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class ArabicStemmerService extends Injectable
{
    private const STEMMER_URL = 'http://host.docker.internal:5050/stem';
    private $httpClient;
    private $logger;

    public function __construct()
    {
        $this->logger = $this->getDI()->getLogger();
        $this->httpClient = new Client([
            'timeout' => 5.0,
            'connect_timeout' => 2.0
        ]);
    }

    /**
     * Get the stem of an Arabic word using the Python service
     *
     * @param string $word
     * @return string Returns the stemmed word, or original word if stemming fails
     */
    public function stemWord(string $word): string
    {
        try {
            $response = $this->httpClient->post(self::STEMMER_URL, [
                'json' => ['text' => $word],
                'headers' => ['Content-Type' => 'application/json']
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            
            if (isset($result['stemmed'])) {
                return $result['stemmed'];
            }
            
            return $word;
        } catch (GuzzleException $e) {
            $this->logger->error(
                'Arabic stemming failed: ' . $e->getMessage(),
                ['exception' => $e, 'word' => $word]
            );
            return $word;
        }
    }
}

<?php

namespace App\Services;

use Phalcon\Di\Injectable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class ArabicStemmerService extends Injectable
{
    private $stemmerUrl;
    private $httpClient;
    private $logger;

    public function __construct()
    {
        $this->logger = $this->getDI()->getLogger();
        
        // Get stemmer URL from environment or use default
        $stemmerHost = getenv('STEMMER_HOST') ?: 'host.docker.internal';
        $stemmerPort = getenv('STEMMER_PORT') ?: '5050';
        $this->stemmerUrl = "{$stemmerHost}:{$stemmerPort}/stem";
        
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
            $response = $this->httpClient->post($this->stemmerUrl, [
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

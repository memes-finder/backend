<?php

namespace App\Services;

use App\Models\Memes;
use App\Models\MemeTags;
use App\Services\Crawlers\Interfaces\CrawlerInterface;
use App\Services\TaggingServices\Traits\TagExtractionTrait;
use GuzzleHttp\Client;
use Aws\S3\S3Client;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Phalcon\Di;

abstract class CrawlerService implements CrawlerInterface
{
    use TagExtractionTrait;

    protected Client $httpClient;
    protected S3Client $s3Client;
    protected string $sourceName;

    protected array $stopWords = [
        'en' => ['the', 'and', 'for', 'that', 'this', 'with', 'was', 'are', 'what', 'when', 'where', 'who', 'how'],
        'ar' => ['من', 'في', 'على', 'الى', 'هذا', 'هذه', 'كان', 'كانت', 'مع', 'عن', 'لكن', 'ثم', 'او', 'أو']
    ];

    protected $config;

    protected array $stats = [
        'total_processed' => 0,
        'total_saved' => 0,
        'total_skipped' => 0,
        'total_errors' => 0
    ];

    public function __construct()
    {
        $this->di = Di::getDefault();
        $this->config = $this->di->get('config');
        
        $this->httpClient = new Client();
        
        $this->s3Client = new S3Client([
            'version' => 'latest',
            'region'  => $this->config->aws->region,
            'credentials' => [
                'key'    => $this->config->aws->key,
                'secret' => $this->config->aws->secret,
            ]
        ]);
    }

    /**
     * Abstract method to be implemented by specific crawlers
     */
    abstract public function crawl(): void;

    /**
     * Save meme to storage and database
     *
     * @param string $imageUrl URL of the image to save
     * @param array $tags Array of tags with confidence scores
     * @param string $language Language of the tags
     * @param string|null $imageContent Optional pre-downloaded image content
     * @param string|null $imageHash Optional pre-computed image hash
     * @return Memes|null Saved meme model or null on failure
     * @throws GuzzleException
     */
    protected function saveMeme(
        string $imageUrl,
        array $tags,
        string $tagsOrigin,
        string $language,
        ?string $imageContent = null,
        ?string $imageHash = null
    ): ?Memes {
        try {
            // Download image if not provided
            if (!$imageContent) {
                $response = $this->httpClient->get($imageUrl);
                $imageContent = $response->getBody()->getContents();
                $imageHash = md5($imageContent);
            }

            // Get image info
            $tempFile = tempnam(sys_get_temp_dir(), 'meme_');
            file_put_contents($tempFile, $imageContent);
            $imageInfo = getimagesize($tempFile);
            
            if (!$imageInfo) {
                unlink($tempFile);
                throw new Exception('Invalid image file');
            }

            // Generate unique filename
            $extension = image_type_to_extension($imageInfo[2], false);
            $filename = sprintf(
                '%s/%s.%s',
                date('Y/m'),
                md5(uniqid() . $imageUrl),
                $extension
            );
            
            // Save to S3
            $this->s3Client->putObject([
                'Bucket' => $this->config->aws->bucket,
                'Key' => $filename,
                'Body' => $imageContent,
                'ContentType' => $imageInfo['mime'],
                'ACL' => 'public-read'
            ]);

            unlink($tempFile);

            // Create meme record
            $meme = new Memes();
            $meme->file_path = $filename;
            $meme->source_url = $imageUrl;
            $meme->source_type = $this->sourceName;
            $meme->width = $imageInfo[0];
            $meme->height = $imageInfo[1];
            $meme->file_size = strlen($imageContent);
            $meme->mime_type = $imageInfo['mime'];
            $meme->file_hash = $imageHash;
            $meme->status = Memes::STATUS_PENDING; // Set default status

            if (!$meme->save()) {
                throw new Exception('Failed to save meme record: ' . implode(', ', $meme->getMessages()));
            }

            // Save original language tags
            $now = date('Y-m-d H:i:s');
            foreach ($tags as $tag => $confidence) {
                $memeTag = new MemeTags();
                $memeTag->meme_id = $meme->id;
                $memeTag->tag = $tag;
                $memeTag->language = $language === 'ar' ? 'arabic' : 'english';
                $memeTag->confidence = $confidence;
                $memeTag->created_at = $now;
                $memeTag->origin = $tagsOrigin;
                $memeTag->save();

                // Index in RedisSearch
                $this->di->get('searchService')->indexTag(
                    $meme->id,
                    $tag,
                    $language,
                    $confidence,
                    $now
                );
            }

            return $meme;
        } catch (Exception $e) {
            error_log('Error saving meme: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Print crawler statistics
     */
    protected function printStats(): void
    {
        echo "\nCrawling completed!\n";
        echo "Total processed: {$this->stats['total_processed']}\n";
        echo "Total saved: {$this->stats['total_saved']}\n";
        echo "Total errors: {$this->stats['total_errors']}\n";
        echo "Total duplicates: {$this->stats['total_duplicates']}\n";
    }
}

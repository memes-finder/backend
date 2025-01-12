<?php

namespace App\Models;

use Aws\S3\S3Client;
use Phalcon\Mvc\Model;

class Memes extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';

    /**
     * @var int
     */
    public $id;

    /**
     * @var string
     */
    public $file_path;

    /**
     * @var string|null
     */
    public $source_url;

    /**
     * @var string|null
     */
    public $source_type;

    /**
     * @var int|null
     */
    public $width;

    /**
     * @var int|null
     */
    public $height;

    /**
     * @var int|null
     */
    public $file_size;

    /**
     * @var string|null
     */
    public $mime_type;

    /**
     * @var string
     */
    public $meme_type;

    /**
     * @var string|null
     */
    public $thumbnail;

    /**
     * @var string|null
     */
    public $blur_hash;

    /**
     * @var float|null
     */
    public $duration;

    /**
     * @var string|null
     */
    public $source_url_hash;

    /**
     * @var string|null
     */
    public $file_hash;

    /**
     * @var string
     */
    public $status = self::STATUS_PENDING;

    /**
     * @var string
     */
    public $created_at;

    /**
     * @var string
     */
    public $updated_at;

    public function initialize()
    {
        $this->setSource('memes');
        
        $this->hasMany(
            'id',
            MemeTags::class,
            'meme_id',
            [
                'alias' => 'tags',
                'reusable' => true
            ]
        );

        $this->hasMany(
            'id',
            MemeViews::class,
            'meme_id',
            [
                'alias' => 'views',
                'reusable' => true
            ]
        );

        $this->hasMany(
            'id',
            MemeLaughs::class,
            'meme_id',
            [
                'alias' => 'likes',
                'reusable' => true
            ]
        );

        // Add behaviors
        $this->addBehavior(
            new \Phalcon\Mvc\Model\Behavior\Timestampable([
                'beforeCreate' => [
                    'field' => 'created_at',
                    'format' => 'Y-m-d H:i:s'
                ],
                'beforeUpdate' => [
                    'field' => 'updated_at',
                    'format' => 'Y-m-d H:i:s'
                ]
            ])
        );
    }

    public function beforeCreate()
    {
        // Set default status if not set
        if (empty($this->status)) {
            $this->status = self::STATUS_PENDING;
        }

        if ($this->source_url && empty($this->source_url_hash)) {
            $this->source_url_hash = md5($this->source_url);
        }
    }

    public function beforeDelete()
    {
        try {
            // Delete all associated tags
            $tags = $this->getTags();
            foreach ($tags as $tag) {
                if (!$tag->delete()) {
                    // Log error but continue
                    $this->getDI()->get('logger')->error(
                        "Failed to delete tag {$tag->id} for meme {$this->id}: " . 
                        implode(', ', $tag->getMessages())
                    );
                }
            }

            // Remove from search index
            try {
                $this->getDI()->get('searchService')->removeMeme($this->id);
            } catch (\Exception $e) {
                // Log error but continue
                $this->getDI()->get('logger')->error(
                    "Failed to remove meme {$this->id} from search index: " . $e->getMessage()
                );
            }

            // Delete the file from S3
            try {
                /** @var S3Client $s3Client */
                $s3Client = $this->getDI()->get('s3Client');
                $s3Client->deleteObject([
                    'Bucket' => $this->getDI()->get('config')->aws->bucket,
                    'Key' => $this->file_path
                ]);
            } catch (\Exception $e) {
                // Log error but continue
                $this->getDI()->get('logger')->error(
                    "Failed to delete S3 object for meme {$this->id}: " . $e->getMessage()
                );
            }

            return true;
        } catch (\Exception $e) {
            $this->getDI()->get('logger')->error(
                "Error in beforeDelete for meme {$this->id}: " . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Approve the meme
     * @return bool
     */
    public function approve(): bool
    {
        $this->status = self::STATUS_APPROVED;
        return $this->save();
    }

    /**
     * Check if meme is approved
     * @return bool
     */
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Get tags by language
     * @param string $language 'en' or 'ar'
     * @return MemeTags[]
     */
    public function getTagsByLanguage(string $language)
    {
        return $this->getTags([
            'conditions' => 'language = :language: AND status = \'approved\'',
            'bind' => ['language' => $language]
        ]);
    }

    /**
     * Get public URL for the meme
     * @return string
     */
    public function getPublicUrl()
    {
        // Example implementation - modify based on your CDN setup
        return sprintf('https://cdn.memesplanet.com/%s', $this->file_path);
    }

    /**
     * Get public URL for the meme
     * @return string
     */
    public function getThumbnailUrl()
    {
        // Example implementation - modify based on your CDN setup
        return sprintf('https://cdn.memesplanet.com/%s', $this->thumbnail);
    }

    /**
     * Get all tags for this meme
     * @param array|null $params Query parameters
     * @return MemeTags[]
     */
    public function getTags($params = null)
    {
        return $this->getRelated('tags', $params);
    }

    /**
     * Get the total number of views for this meme
     * @return int
     */
    public function getViewCount(): int
    {
        return MemeViews::count([
            'conditions' => 'meme_id = :meme_id:',
            'bind' => ['meme_id' => $this->id]
        ]);
    }

    /**
     * Get the total number of likes for this meme
     * @return int
     */
    public function getLaughsCount(): int
    {
        return MemeLaughs::count([
            'conditions' => 'meme_id = :meme_id:',
            'bind' => ['meme_id' => $this->id]
        ]);
    }

    /**
     * Check if the given IP has liked this meme
     * @param string $ipAddress
     * @return bool
     */
    public function isLikedByIp(string $ipAddress): bool
    {
        return MemeLaughs::count([
            'conditions' => 'meme_id = :meme_id: AND ip_address = :ip:',
            'bind' => [
                'meme_id' => $this->id,
                'ip' => $ipAddress
            ]
        ]) > 0;
    }

    /**
     * Convert meme to array with tags grouped by language
     * @return array
     */
    public function toArray($columns = null): array
    {
        $data = parent::toArray($columns);
        
        // Get tags grouped by language
        $tags = ['en' => [], 'ar' => []];
        foreach ($this->getTags() as $tag) {
            $tags[$tag->language][] = $tag->tag;
        }
        
        $data['tags'] = $tags;
        $data['public_url'] = $this->getPublicUrl();
        $data['view_count'] = $this->getViewCount();
        $data['like_count'] = $this->getLaughsCount();
        
        return $data;
    }
}

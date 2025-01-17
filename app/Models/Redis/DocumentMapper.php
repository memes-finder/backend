<?php
/**
 * User: Wajdi Jurry
 * Date: 23/02/19
 * Time: 06:35 م
 */

namespace App\Models\Redis;

use Ehann\RediSearch\Document\Document;
use Ehann\RediSearch\Fields\TextField;
use Ehann\RediSearch\Fields\NumericField;
use Ehann\RediSearch\Fields\TagField;

class DocumentMapper extends Document
{
    /** @var null|string */
    public $id;

    /** @var TextField */
    public $tag;

    /** @var TextField */
    public $tag_normalized;

    /** @var TagField */
    public $language;

    /** @var NumericField */
    public $meme_id;

    /** @var TagField */
    public $meme_type;

    /** @var NumericField */
    public $confidence;

    /**
     * DocumentMapper constructor.
     * @param string $id
     * @throws \Exception
     */
    public function __construct(?string $id = null)
    {
        $this->id = $id;
        parent::__construct($id);
    }

    /**
     * Create a document for indexing a meme
     * 
     * @param int $memeId
     * @param array $tags Array of tags
     * @param array $normalizedTags Array of normalized tags
     * @param string $language
     * @param string $memeType
     * @param float $confidence
     * @return $this
     */
    public function makeDocument(int $memeId, array $tags, array $normalizedTags, string $language, string $memeType, float $confidence = 1.0)
    {
        $this->meme_id = new NumericField('meme_id', $memeId);
        $this->tag = new TextField('tag', implode(' | ', $tags));
        $this->tag_normalized = new TextField('tag_normalized', implode(' | ', $normalizedTags));
        $this->language = new TagField('language', $language);
        $this->meme_type = new TagField('meme_type', $memeType);
        $this->confidence = new NumericField('confidence', $confidence);
        return $this;
    }

    public function getLanguage()
    {
        return $this->language->getValue();
    }
}
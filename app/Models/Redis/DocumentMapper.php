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
     * @param string $tag
     * @param string $normalizedTag
     * @param string $language
     * @param string $memeType
     * @return $this
     */
    public function makeDocument(int $memeId, string $tag, string $normalizedTag, string $language, string $memeType)
    {
        $this->meme_id = new NumericField('meme_id', $memeId);
        $this->tag = new TextField('tag', $tag);
        $this->tag_normalized = new TextField('tag_normalized', $normalizedTag);
        $this->language = new TagField('language', $language);
        $this->meme_type = new TagField('meme_type', $memeType);
        return $this;
    }

    public function getLanguage()
    {
        return $this->language->getValue();
    }
}
<?php
namespace Xyng\Yuoshi\Schema;

use JsonApi\Schemas\SchemaProvider;
use Neomerx\JsonApi\Document\Link;

class Answers extends SchemaProvider
{
    const TYPE = 'answers';
    protected $resourceType = self::TYPE;

    /**
     * @inheritDoc
     */
    public function getId($resource)
    {
        return $resource->getId($resource);
    }

    /**
     * @inheritDoc
     */
    public function getAttributes($resource)
    {
        return [
            'content' => $resource->content,
            'is_correct' => $resource->is_correct,
            'mkdate' => $resource->mkdate->format('c'),
            'chdate' => $resource->chdate->format('c'),
        ];
    }
}

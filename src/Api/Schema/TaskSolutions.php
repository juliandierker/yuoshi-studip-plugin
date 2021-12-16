<?php
namespace Xyng\Yuoshi\Api\Schema;

use JsonApi\Schemas\SchemaProvider;
use Neomerx\JsonApi\Document\Link;

class TaskSolutions extends SchemaProvider {
    const TYPE = 'task_solutions';
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
        /** @var \Xyng\Yuoshi\Model\UserTaskSolutions $resource */
        return [
            'task_id' => $resource->task_id,
            'points' => (int) $resource->points,
            'is_correct' => (bool) $resource->is_correct,
            'finished' => $resource->finished ? $resource->finished->format('c') : null,
            'mkdate' => $resource->mkdate->format('c'),
            'chdate' => $resource->chdate->format('c'),
        ];
    }

    public function getRelationships($resource, $isPrimary, array $includeRelationships)
    {
        /** @var \Xyng\Yuoshi\Model\UserTaskSolutions $resource */
        $task = null;
        if ($includeRelationships['task'] ?? null) {
            $task = $resource->task;
        }

        $content_solutions = null;
        if ($includeRelationships['content_solutions'] ?? null) {
            $content_solutions = $resource->content_solutions;
        }

        $user = null;
        if ($includeRelationships['user'] ?? null) {
            $user = $resource->user;
        }

        $current_content_solution = null;
        if ($includeRelationships['current_content_solution'] ?? null) {
            $current_content_solution = $resource->current_content_solution;
        }

        $done_content_solutions = null;
        if ($includeRelationships['done_content_solutions'] ?? null) {
            $done_content_solutions = $resource->done_content_solutions;
        }

        return [
            'task' => [
                self::DATA => $task,
                self::SHOW_SELF => true,
                self::LINKS => [
                    Link::RELATED => $this->getRelationshipRelatedLink($resource, 'task')
                ],
            ],
            'content_solutions' => [
                self::DATA => $content_solutions,
                self::SHOW_SELF => true,
                self::LINKS => [
                    Link::RELATED => $this->getRelationshipRelatedLink($resource, 'content_solutions')
                ],
            ],
            'user' => [
                self::DATA => $user,
                self::SHOW_SELF => true,
                self::LINKS => [
                    Link::RELATED => $this->getRelationshipRelatedLink($resource, 'user')
                ],
            ],
            'current_content_solution' => [
                self::DATA => $current_content_solution,
                self::SHOW_SELF => true,
                self::LINKS => [
                    Link::SELF => $this->getRelationshipSelfLink($resource, 'current_content_solution')
                ],
            ],
            'done_content_solutions' => [
                self::DATA => $done_content_solutions,
                self::SHOW_SELF => true,
                self::LINKS => [
                    Link::SELF => $this->getRelationshipSelfLink($resource, 'done_content_solutions')
                ],
            ],
        ];
    }
}

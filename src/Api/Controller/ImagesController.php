<?php
namespace Xyng\Yuoshi\Api\Controller;

use File;
use FileRef;
use Folder;
use JsonApi\Errors\InternalServerError;
use JsonApi\Errors\RecordNotFoundException;
use JsonApi\Errors\UnprocessableEntityException;
use JsonApi\NonJsonApiController;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use SimpleORMap;
use Slim\Http\Stream;
use StandardFolder;
use User;
use Xyng\Yuoshi\Authority\TaskAuthority;
use Xyng\Yuoshi\Authority\TaskContentAuthority;
use Xyng\Yuoshi\Authority\TaskContentQuestAnswerAuthority;
use Xyng\Yuoshi\Authority\TaskContentQuestAuthority;
use Xyng\Yuoshi\Helper\DBHelper;
use Xyng\Yuoshi\Helper\PermissionHelper;
use Xyng\Yuoshi\Helper\QueryField;
use Xyng\Yuoshi\Model\Files;
use Xyng\Yuoshi\Model\LearningObjectives;
use Xyng\Yuoshi\Model\Tasks;

use Xyng\Yuoshi\Model\TaskContentQuestAnswers;
use Xyng\Yuoshi\Model\TaskContentQuests;
use Xyng\Yuoshi\Model\TaskContents;

class ImagesController extends NonJsonApiController
{
    public function show(ServerRequestInterface $request, ResponseInterface $response, $args)
    {
        $image_id = $args['image_id'] ?? null;

        if (!$image_id) {
            return $response->withStatus(404);
        }

        try {
            $fileRef = $this->findFileRef($image_id, $request);
        } catch (RecordNotFoundException $e) {
            return $response->withStatus(404);
        } catch (\Exception $e) {
            return $response->withStatus(500);
        }

        /** @var File $file */
        $file = $fileRef->file;

        if (!file_exists($file->getPath())) {
            return $response->withStatus(404);
        }

        $resource = fopen($file->getPath(), 'r');
        return $response->withStatus(200)->withHeader('Content-Type', $file->mime_type)->withBody(
            new Stream($resource)
        );
    }

    /**
     * @param string $model
     * @param string $id
     * @return TaskContents|LearningObjectives|null
     */
    protected function _getForeignEntity(string $model, string $id)
    {
        // TODO: add cases for all models that have files

        switch ($model) {
            case \Xyng\Yuoshi\Api\Schema\Contents::TYPE:
                return TaskContents::find($id);
            case \Xyng\Yuoshi\Api\Schema\LearningObjectives::TYPE:
                return LearningObjectives::find($id);
            case \Xyng\Yuoshi\Api\Schema\Tasks::TYPE:
                return Tasks::find($id);
        }
    }

    protected function _getCourseIdForModel(SimpleORMap $model): string
    {
        if ($model instanceof Tasks) {
            /** @var Tasks $model */
            return $model->station->package->course_id;
        } elseif ($model instanceof TaskContents) {
            /** @var TaskContents $model */
            return $model->task->station->package->course_id;
        } elseif ($model instanceof LearningObjectives) {
            /** @var LearningObjectives $model */
            return $model->package->course_id;
        }

        // TODO: implement for other models

        throw new UnprocessableEntityException();
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, $args)
    {
        /** @var UploadedFileInterface|null $image */
        $image = $request->getUploadedFiles()['file'] ?? null;
        $fk_model = $request->getParsedBody()['model'] ?? null;
        $fk_key = $request->getParsedBody()['key'] ?? null;
        $fk_group = $request->getParsedBody()['group'] ?? null;

        $model = $this->_getForeignEntity($fk_model, $fk_key);

        if (!$model) {
            throw new UnprocessableEntityException();
        }

        try {
            $model->getValue($fk_group);
        } catch (\Exception $e) {
            throw new UnprocessableEntityException();
        }

        $course_id = $this->_getCourseIdForModel($model);

        // TODO: check if user has access to course

        if (!$image || !$course_id) {
            throw new UnprocessableEntityException();
        }

        /** @var Folder|null $dbFolder */
        $dbFolder = \Folder::findOneBySQL(
            "parent_id='' AND range_id = :range_id AND range_type = :range_type",
            [
                'range_id' => $course_id,
                'range_type' => 'course',
            ]
        );

        if (!$dbFolder) {
            $dbFolder = \Folder::createTopFolder($entity->id, get_class($entity), 'StandardFolder');
        }

        if (!$dbFolder) {
            throw new InternalServerError("could not create folder");
        }

        /** @var StandardFolder $folder */
        $folder = $dbFolder->getTypedFolder();

        $file = new File();
        $file->name = $dbFolder->getUniqueName(md5($image->getClientFilename() ?: uniqid()));
        $file->mime_type = $image->getClientMediaType();
        $file->size = $image->getSize();
        $file->storage = 'disk';
        $file->user_id = $this->getUser($request)->id;
        $file->id = $file->getNewId();

        $this->updateFile($file, $image);

        $fileRef = $folder->createFile($file);

        try {
            $model->{$fk_group} = [
                Files::buildForRef($fileRef),
            ];
            $model->image = $file->id;

            $model->store();
        } catch (\Exception $e) {
            // ignore for now
        }

        $stream = new Stream(fopen('php://temp', 'r+'));
        $stream->write(json_encode([
            'fileRefId' => $fileRef->id,
            'fileId' => $fileRef->file_id,
            'fileName' => $fileRef->name
        ]));

        return $response
            ->withHeader(
                'Content-Type',
                'application/json'
            )
            ->withStatus(201)
            ->withBody($stream);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, $args)
    {
        /** @var UploadedFileInterface|null $image */
        $image = $request->getUploadedFiles()['image'] ?? null;

        if (!$image) {
            throw new UnprocessableEntityException();
        }

        $image_id = $args['image_id'] ?? null;

        if (!$image_id) {
            throw new RecordNotFoundException();
        }

        $fileRef = $this->findFileRef($image_id, $request, 'dozent');

        /** @var File $file */
        $file = $fileRef->file;

        $this->updateFile($file, $image);
        $file->size = $image->getSize();
        $file->mime_type = $image->getClientMediaType();

        $file->store();

        return $response->withStatus(204);
    }

    /**
     * Find FileRef by ID, scoped by TaskContent, TaskContentQuest or TaskContentQuestAnswer
     *
     * @param string $id ID of file
     * @param ServerRequestInterface $request Server request
     * @param string|null $perm (optional) required user permissions in TaskContent
     * @return FileRef|null
     */
    protected function findFileRef(string $id, ServerRequestInterface $request, string $perm = null)
    {
        $conditions = $perm ? [
            'seminar_user.status IN' => PermissionHelper::getMasters($perm)
        ] : [];

        // filter files by course so that only files of users courses can be found.
        ['sql' => $sql, 'params' => $params] = DBHelper::queryToSql([
            'conditions' => [
                'file_refs.id' => $id
            ],
            'joins' => [
                [
                    'type' => 'INNER',
                    'table' => 'folders',
                    'on' => [
                        'folders.id' => new QueryField('file_refs.folder_id')
                    ],
                ],
                [
                    'type' => 'INNER',
                    'table' => 'seminare',
                    'on' => [
                        'seminare.Seminar_id' => new QueryField('folders.range_id'),
                        'folders.range_type' => 'course',
                    ],
                ],
                [
                    'type' => 'INNER',
                    'table' => 'seminar_user',
                    'on' => array_merge($conditions, [
                        'seminar_user.Seminar_id' => new QueryField('seminare.Seminar_id'),
                        'seminar_user.user_id' => $this->getUser($request)->id
                    ]),
                ],
            ]
        ]);

        /** @var FileRef|null $fileRef */
        $fileRef = \FileRef::findOneBySQL($sql, $params);

        if (!$fileRef) {
            throw new RecordNotFoundException();
        }

        return $fileRef;
    }

    protected function updateFile(File $file, UploadedFileInterface $image)
    {
        $newPath = $file->getPath();
        if (!is_dir(pathinfo($newPath, PATHINFO_DIRNAME))) {
            mkdir(pathinfo($newPath, PATHINFO_DIRNAME), 0777, true);
        }

        $image->moveTo($file->getPath());
    }
}

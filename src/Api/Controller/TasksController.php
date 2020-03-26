<?php
namespace Xyng\Yuoshi\Api\Controller;

use Course;
use JsonApi\Errors\AuthorizationFailedException;
use JsonApi\Errors\InternalServerError;
use JsonApi\Errors\RecordNotFoundException;
use JsonApi\Errors\UnprocessableEntityException;
use JsonApi\JsonApiController;
use JsonApi\Routes\any;
use JsonApi\Routes\Courses\Authority as CourseAuthority;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Valitron\Validator;
use Xyng\Yuoshi\Api\Exception\ValidationException;
use Xyng\Yuoshi\Api\Helper\JsonApiDataHelper;
use Xyng\Yuoshi\Api\Helper\ValidationTrait;
use Xyng\Yuoshi\Model\Packages;
use Xyng\Yuoshi\Model\Tasks;

class TasksController extends JsonApiController
{
    use ValidationTrait;

    protected $allowedPagingParameters = ['offset', 'limit'];
    protected $allowedFilteringParameters = ['sequence', 'package'];

    public function index(ServerRequestInterface $request, ResponseInterface $response, $args) {
        $package_id = $args['id'] ?? null;
        $package_ids = $package_id ? [$package_id] : [];

        $filters = $this->getQueryParameters()->getFilteringParameters();
        if (!$package_ids) {
            $package_ids = explode(',', $filters['package'] ?? '');
        }
        $sequence = $filters['sequence'] ?? null;

        if ($sequence) {
            $where['sequence'] = $sequence;
        }

        if (!$package_ids) {
            throw new \InvalidArgumentException("Cannot select Tasks without package filter.");
        }

        $where['package_id IN'] = $package_ids;

        $tasks = Tasks::findWhere($where);

        list($offset, $limit) = $this->getOffsetAndLimit();

        return $this->getPaginatedContentResponse(
            array_slice($tasks, $offset, $limit),
            count($tasks)
        );
    }

    public function nextTask(ServerRequestInterface $request, ResponseInterface $response, $args) {
        $sql = <<<'EOD'
            LEFT JOIN yuoshi_user_task_solutions Solutions ON (
                Solutions.task_id = yuoshi_tasks.id AND
                Solutions.user_id = :user_id
            )
            WHERE (
                Solutions.id IS NULL
                AND `yuoshi_tasks`.`package_id` = :package_id
            )
            ORDER BY `yuoshi_tasks`.`sequence`
EOD;

        ['id' => $package_id] = $args;
        $task = Tasks::findOneBySQL(trim($sql), [
            'user_id' => $this->getUser($request)->id,
            'package_id' => $package_id,
        ]);

        return $this->getContentResponse($task);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, $args) {
        ['id' => $package_id] = $args;
        /** @var Packages|null $task */
        $task = Packages::find($package_id);

        if ($task == null) {
            throw new RecordNotFoundException();
        }

        if (!\PackageAuthority::canEditPackage($this->getUser($request), $task)) {
            throw new AuthorizationFailedException();
        }

        $data = $request->getParsedBody();

        $validator = new Validator($data);
        $validator
            ->rule('required', 'title')
            ->rule('required', 'kind')
            ->rule('required', 'credits')
            ->rule('required', 'sequence')
            ->rule('required', 'is_training')
            ->rule('boolean', 'is_training')
            ->rule('integer', 'sequence')
            ->rule('integer', 'credits')
            // TODO: add all possible Task-Types
            ->rule('in', 'kind', ['multi'])
        ;

        if (!$validator->validate()) {
            throw new ValidationException($validator);
        }

        $task = Tasks::build([
            'title' => $data['title'],
            'course_id' => $package_id,
        ]);

        if (!$task->store()) {
            throw new InternalServerError("could not persist entity");
        }

        return $this->getContentResponse($task);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, $args) {
        $task_id = $args['id'] ?? null;

        if ($task_id === null) {
            throw new RecordNotFoundException();
        }

        $task = Tasks::find($task_id);

        if (!$task) {
            throw new RecordNotFoundException();
        }

        /** @var Course $course */
        $course = $task->package->course;

        if (!CourseAuthority::canEditCourse($this->getUser($request), $course, CourseAuthority::SCOPE_BASIC)) {
            throw new AuthorizationFailedException();
        }

        $validated = $this->validate($request);

        $data = new JsonApiDataHelper($validated);
        $attrs = $data->getAttributes(['title', 'kind', 'description', 'sequence', 'is_training', 'credits']);

        foreach ($attrs as $key => $value) {
            $task->{$key} = $value ?? $task->{$key};
        }

        // TODO: handle image

        if ($task->isDirty() && !$task->store()) {
            throw new InternalServerError("could not update package");
        }

        return $this->getContentResponse($task);
    }


    /**
     * @inheritDoc
     */
    protected function buildResourceValidationRules(Validator $validator, $data): Validator
    {
        return $validator;
    }
}

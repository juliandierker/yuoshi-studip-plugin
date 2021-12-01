<?php
namespace Xyng\Yuoshi\Api\Controller;

use JsonApi\Errors\AuthorizationFailedException;
use JsonApi\Errors\InternalServerError;
use JsonApi\Errors\RecordNotFoundException;
use JsonApi\JsonApiController;
use Xyng\Yuoshi\Api\Helper\JsonApiDataHelper;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xyng\Yuoshi\Api\Helper\ValidationTrait;
use Valitron\Validator;

use Xyng\Yuoshi\Authority\LearningObjectiveAuthority;
use Xyng\Yuoshi\Authority\PackageAuthority;
use Xyng\Yuoshi\Authority\StationAuthority;
use Xyng\Yuoshi\Helper\PermissionHelper;
use Xyng\Yuoshi\Model\Files;
use Xyng\Yuoshi\Model\LearningObjectives;
use Xyng\Yuoshi\Model\Stations;
use Xyng\Yuoshi\Model\Tasks;

class LearningObjectivesController extends JsonApiController
{
    use ValidationTrait;

    protected $allowedPagingParameters = ['offset', 'limit'];
    protected $allowedFilteringParameters = ['package', 'sort'];
    protected $allowedIncludePaths = ['package', 'images.file.file'];

    public function index(ServerRequestInterface $request, ResponseInterface $response, $args)
    {
        $package_id = $args['id'] ?? null;
        $filters = $this->getQueryParameters()->getFilteringParameters();

        if (!$package_id) {
            $package_id = $filters['package'] ?? null;
        }

        if (!$package_id) {
            throw new \InvalidArgumentException("Cannot select Package.");
        }

        $learning_objectives = LearningObjectiveAuthority::findFiltered([$package_id], $this->getUser($request));
        list($offset, $limit) = $this->getOffsetAndLimit();
        
        return $this->getPaginatedContentResponse(
            array_slice($learning_objectives, $offset, $limit),
            count($learning_objectives)
        );
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, $args)
    {
        $id = $args['id'] ?? null;

        if (!$id) {
            $filters = $this->getQueryParameters()->getFilteringParameters();
            $id = $filters['id'] ?? null;
        }

        $user = $this->getUser($request);
        /** @var LearningObjectives|null $learning_objective */
        $learning_objective = LearningObjectiveAuthority::findOneFiltered($id, $user);

        if (!$learning_objective) {
            throw new RecordNotFoundException();
        }

        return $this->getContentResponse($learning_objective);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, $args)
    {
        $validated = $this->validate($request, true);
        $data = new JsonApiDataHelper($validated);
        $attributes = $data->getAttributes(['title', 'description', 'image', 'sort']);
        $package_id = $data->getRelation('package')['data']['id'] ?? null;

        /** @var Package|null $package */
        $package = PackageAuthority::findOneFiltered($package_id, $this->getUser($request), PermissionHelper::getMasters('dozent'));

        if ($package == null) {
            throw new RecordNotFoundException();
        }

        if (!PackageAuthority::canEditPackage($this->getUser($request), $package)) {
            throw new AuthorizationFailedException();
        }

        $learning_objective = LearningObjectives::build(
            $attributes
            +
            [
                'package_id' => $package_id,
            ]
        );
        $learning_objective->sort = LearningObjectives::nextSort($package->id);

        if (!$learning_objective->store()) {
            throw new InternalServerError("could not persist entity");
        }
        $this->createTextsForLearningObjective($learning_objective, $this->getUser($request));
        $this->createQuestionForObjective($learning_objective, $this->getUser($request));

        return $this->getContentResponse($learning_objective);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, $args)
    {
        $learning_objective_id = $args['id'] ?? null;
        if ($learning_objective_id === null) {
            throw new RecordNotFoundException();
        }

        $learning_objective= LearningObjectiveAuthority::findOneFiltered($learning_objective_id, $this->getUser($request), PermissionHelper::getMasters('dozent'));

        if (!$learning_objective_id) {
            throw new RecordNotFoundException();
        }

        $validated = $this->validate($request);
        $data = new JsonApiDataHelper($validated);

        if ($title = $data->getAttribute('title')) {
            $learning_objective->title = $title;
        }

        if ($description = $data->getAttribute('description')) {
            $learning_objective->description = $description;
        }

        if ($image = $data->getAttribute('image')) {
            $learning_objective->image = $image;
        }

        if ($sort = $data->getAttribute('sort')) {
            $learning_objective->sort = $sort;
        }

        if ($learning_objective->isDirty() && !$learning_objective->store()) {
            throw new InternalServerError("could not update package");
        }

        return $this->getContentResponse($learning_objective);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, $args)
    {
        $objective_id = $args['objective_id'] ?? null;

        if (!objective_id) {
            throw new RecordNotFoundException();
        }

        $objective = LearningObjectiveAuthority::findOneFiltered($objective_id, $this->getUser($request), PermissionHelper::getMasters('dozent'));

        if (!$objective->delete()) {
            throw new InternalServerError("could not delete entity");
        }

        return $response->withStatus(204);
    }

    /**
     * @inheritDoc
     */
    protected function buildResourceValidationRules(Validator $validator, $new = false): Validator
    {
        $validator
            ->rule('required', 'data.attributes.title')
            ->rule('required', 'data.attributes.description')
            ->rule('numeric', 'data.attributes.sort');

        if ($new) {
            $validator
                ->rule('required', 'data.relationships.package.data.id');
        }

        return $validator;
    }

    protected function createTextsForLearningObjective($learning_objective, $user)
    {
        $objectiveStations = StationAuthority::findFiltered([$learning_objective->package_id], $user, PermissionHelper::getMasters('dozent'));
        $targetStationKey = array_search('pupiltexts', array_column($objectiveStations, 'slug'));
        $targetStation = $objectiveStations[$targetStationKey];
        $task_count = Tasks::countWithQuery(['conditions' => [
            'station_id' => $targetStation->id
        ]]);

        $task = Tasks::build(
            [
                'title' => $learning_objective->title,
                'kind' => 'tag',
                'description' => 'Beschreibung wählen',
                'credits' => 0,
                'sort' => $task_count,
                'is_training' => false,
                'station_id' => $targetStation->id,
            ]
        );
        if (!$task->store()) {
            throw new InternalServerError("could not persist entity");
        }
    }

    protected function createQuestionForObjective($learning_objective, $user)
    {
        $objectiveStations = StationAuthority::findFiltered([$learning_objective->package_id], $user, PermissionHelper::getMasters('dozent'));
        $targetStationKey = array_search('quest', array_column($objectiveStations, 'slug'));
        $targetStation = $objectiveStations[$targetStationKey];
        $task_count = Tasks::countWithQuery(['conditions' => [
            'station_id' => $targetStation->id
        ]]);

        $task = Tasks::build(
            [
                'title' => $learning_objective->title,
                'kind' => 'multi',
                'description' => 'Beschreibung wählen',
                'credits' => 0,
                'sort' => $task_count,
                'is_training' => false,
                'station_id' => $targetStation->id,
            ]
        );
        if (!$task->store()) {
            throw new InternalServerError("could not persist entity");
        }
    }
}

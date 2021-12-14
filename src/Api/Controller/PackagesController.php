<?php
namespace Xyng\Yuoshi\Api\Controller;

use Course;
use JsonApi\Errors\AuthorizationFailedException;
use JsonApi\Errors\InternalServerError;
use JsonApi\Errors\RecordNotFoundException;
use JsonApi\JsonApiController;
use JsonApi\Routes\Courses\Authority as CourseAuthority;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use Valitron\Validator;
use Xyng\Yuoshi\Authority\PackageAuthority;
use Xyng\Yuoshi\Api\Helper\JsonApiDataHelper;
use Xyng\Yuoshi\Api\Helper\ValidationTrait;
use Xyng\Yuoshi\Helper\AuthorityHelper;
use Xyng\Yuoshi\Helper\PermissionHelper;
use Xyng\Yuoshi\Helper\QueryField;
use Xyng\Yuoshi\Model\Packages;
use Xyng\Yuoshi\Model\Stations;

class PackagesController extends JsonApiController
{
    use ValidationTrait;

    protected $allowedFilteringParameters = ['course', 'sort'];
    protected $allowedPagingParameters = ['offset', 'limit'];
    protected $allowedIncludePaths = ['packageTotalProgress', 'packageUserProgress', 'packageUserProgress.user'];

    public function index(ServerRequestInterface $request, ResponseInterface $response, $args)
    {
        $course_id = $args['id'] ?? null;

        $filters = $this->getQueryParameters()->getFilteringParameters();

        if (!$course_id) {
            $course_id = $filters['course'] ?? null;
        }

        $conditions = [];
        if ($sort = ($filters['sort'] ?? null)) {
            $conditions['sort'] = $sort;
        }

        $user = $this->getUser($request);
        $packages = PackageAuthority::findFiltered([$course_id], $user, [], [
            'conditions' => $conditions,
            'order' => 'yuoshi_packages.sort ASC'
        ]);

        list($offset, $limit) = $this->getOffsetAndLimit();

        return $this->getPaginatedContentResponse(
            array_slice($packages, $offset, $limit),
            count($packages)
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
        $package = PackageAuthority::findOneFiltered($id, $user);

        if (!$package) {
            throw new RecordNotFoundException();
        }

        return $this->getContentResponse($package);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, $args)
    {
        $validated = $this->validate($request, true);
        $data = new JsonApiDataHelper($validated);
        $attributes = $data->getAttributes(['title', 'slug', 'sort']);

        /** @var Course|null $course */
        $course = Course::find($data->getRelation('course')['data']['id'] ?? null);

        if ($course == null) {
            throw new RecordNotFoundException();
        }

        if (!CourseAuthority::canEditCourse($this->getUser($request), $course, CourseAuthority::SCOPE_BASIC)) {
            throw new AuthorizationFailedException();
        }

        $package = Packages::build($attributes);
        $package->sort = Packages::nextSort($course->id);
        $package->course_id = $course->id;

        if (!$package->store()) {
            throw new InternalServerError("could not save package");
        }

        $this->createInitialStations($package);
        return $this->getContentResponse($package);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, $args)
    {
        $package_id = $args['id'] ?? null;

        if ($package_id === null) {
            throw new RecordNotFoundException();
        }

        $package = PackageAuthority::findOneFiltered($package_id, $this->getUser($request), PermissionHelper::getMasters('dozent'));

        if (!$package) {
            throw new RecordNotFoundException();
        }

        $validated = $this->validate($request);

        $data = new JsonApiDataHelper($validated);

        if ($title = $data->getAttribute('title')) {
            $package->title = $title;
        }

        if ($slug = $data->getAttribute('slug')) {
            $package->slug = $slug;
        }

        $sort = $data->getAttribute('sort');
        if ($sort !== null) {
            $package->sort = $sort;
        }

        if ($package->isDirty() && !$package->store()) {
            throw new InternalServerError("could not update package");
        }

        return $this->getContentResponse($package);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, $args)
    {
        $package_id = $args['package_id'] ?? null;

        if (!$package_id) {
            throw new RecordNotFoundException();
        }

        $package = PackageAuthority::findOneFiltered($package_id, $this->getUser($request), PermissionHelper::getMasters('dozent'));

        if (!$package->delete()) {
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
            ->rule('required', 'data.attributes.slug')
            ->rule('numeric', 'data.attributes.sort');

        if ($new) {
            $validator
                ->rule('required', 'data.relationships.course.data.id');
        }

        return $validator;
    }

    protected function createInitialStations($package)
    {
        // create initial stations for texts

        $stationOverview = Stations::build(
            [
                'package_id' => $package->id,
                'title' => 'Deine Schüler:innen im Überblick',
                'slug' => strtolower('pupiltexts'),
            ]
        );
        $stationOverview->sort = Stations::nextSort($package->id);
        $stationOverview->package->id = $package->id;

        if (!$stationOverview->store()) {
            throw new InternalServerError("could not save belonging station");
        }

        // create initial stations for Quest

        $stationQuest = Stations::build(
            [
                'package_id' => $package->id,
                'title' => 'Quest',
                'slug' => strtolower('quest'),
            ]
        );
        $stationQuest->sort = Stations::nextSort($package->id);
        $stationQuest->package->id = $package->id;

        if (!$stationQuest->store()) {
            throw new InternalServerError("could not save belonging station");
        }
    }
}

<?php
namespace Xyng\Yuoshi\Model;

/**
 * Class UserTaskSolutions
 * @package Xyng\Yuoshi\Model
 *
 * @property string $task_id
 * @property string $user_id
 * @property number|null $points
 * @property boolean $is_correct
 *
 * @property Tasks $task
 * @property \SimpleORMapCollection|UserTaskContentSolutions[] $content_solutions
 */
class UserTaskSolutions extends BaseModel {
    protected static function configure($config = []) {
        $config['db_table'] = 'yuoshi_user_task_solutions';

        $config['has_many']['content_solutions'] = [
            'on_store' => true,
            'class_name' => UserTaskContentSolutions::class,
            'assoc_foreign_key' => 'solution_id'
        ];

        $config['belongs_to']['task'] = [
            'class_name' => Tasks::class,
            'foreign_key' => 'task_id'
        ];
        $config['belongs_to']['user'] = [
            'class_name' => \User::class,
            'foreign_key' => 'user_id'
        ];

        $config['additional_fields']['is_correct'] = [
            'get' => function (UserTaskSolutions $solution) {
                return $solution->points && $solution->points === $solution->task->credits;
            }
        ];

        parent::configure($config);
    }
}

<?php
/**
 * @link https://github.com/2amigos/yii2-taggable-behavior
 * @copyright Copyright (c) 2013 Alexander Kochetov
 * @license http://opensource.org/licenses/BSD-3-Clause
 */

namespace dosamigos\behaviors;

use yii\base\Behavior;
use yii\base\Event;
use yii\db\ActiveRecord;
use yii\db\Query;

/**
 * Taggable behavior description.
 * @author Alexander Kochetov <creocoder@gmail.com>
 */
class Taggable extends Behavior
{
	/**
	 * @var ActiveRecord the owner of this behavior.
	 */
	public $owner;
	/**
	 * @var string
	 */
	public $attribute = 'tagNames';
	/**
	 * @var string
	 */
	public $name = 'name';
	/**
	 * @var string
	 */
	public $frequency = 'frequency';
	/**
	 * @var string
	 */
	public $relation = 'tags';

	/**
	 * @inheritdoc
	 */
	public function events()
	{
		return [
			ActiveRecord::EVENT_AFTER_FIND => 'afterFind',
			ActiveRecord::EVENT_AFTER_INSERT => 'afterSave',
			ActiveRecord::EVENT_AFTER_UPDATE => 'afterSave',
			ActiveRecord::EVENT_BEFORE_DELETE => 'beforeDelete',
		];
	}

	/**
	 * @param Event $event
	 */
	public function afterFind($event)
	{
		if ($this->owner->isRelationPopulated($this->relation)) {
			$items = array();

			foreach ($this->{$this->relation} as $tag) {
				$items[] = $tag->{$this->name};
			}

			$this->{$this->attribute} = implode(', ', $items);
		}
	}

	/**
	 * @param Event $event
	 */
	public function afterSave($event)
	{
		if ($this->owner->{$this->attribute} === null) {
			return;
		}

		$relation = $this->owner->getRelation($this->relation);
		$pivot = $relation->via->from[0];

		if (!$this->owner->getIsNewRecord()) {
			$this->beforeDelete($event);
			$this->owner->getDb()
				->createCommand()
				->delete($pivot, [key($relation->via->link) => $this->owner->getPrimaryKey()])
				->execute();
		}

		$names = array_unique(preg_split(
			'/\s*,\s*/u',
			preg_replace('/\s+/u', ' ', $this->owner->{$this->attribute}),
			-1,
			PREG_SPLIT_NO_EMPTY
		));

		/** @var ActiveRecord $class */
		$class = $relation->modelClass;

		foreach ($names as $name) {
			$tag = $class::find([$this->name => $name]);

			if ($tag === null) {
				$tag = new $class();
				$tag->{$this->name} = $name;
			}

			$tag->{$this->frequency}++;

			if (!$tag->save()) {
				continue;
			}

			$this->owner->getDb()
				->createCommand()
				->insert($pivot, [
					key($relation->via->link) => $this->owner->getPrimaryKey(),
					current($relation->link) => $tag->getPrimaryKey(),
				])
				->execute();
		}
	}

	/**
	 * @param Event $event
	 */
	public function beforeDelete($event)
	{
		$relation = $this->owner->getRelation($this->relation);
		$pivot = $relation->via->from[0];
		/** @var ActiveRecord $class */
		$class = $relation->modelClass;
		$query = new Query();
		$pks = $query
			->select(current($relation->link))
			->from($pivot)
			->where([key($relation->via->link) => $this->owner->getPrimaryKey()])
			->column($this->owner->getDb());

		if (!empty($pks)) {
			$class::updateAllCounters([$this->frequency => -1], ['in', $class::primaryKey(), $pks]);
		}
	}
}
<?php
	namespace B\Modules\Advert\Models;

	use B\Library\Helper\RedisHelper;
	use Phalcon\DI\FactoryDefault;

	class Category extends \Phalcon\Mvc\Model
	{
    public function getSource() {
			return 'category';
		}

		protected static function _createKey($parameters) {
			$uniqueKey = ['category_model_', get_called_class()];

			foreach($parameters as $key => $value)
				if(is_scalar($value))
					$uniqueKey[] = $key . ":" . $value;
				elseif (is_array($value))
					$uniqueKey[] = $key . ":[" . self::_createKey($value) . "]";

			return join(",", $uniqueKey);
		}

		public static function find($parameters = null) {
			if(!is_array($parameters))
				$parameters = [$parameters];

			if(!isset($parameters["cache"]))
				$parameters["cache"] = [
					"key" => self::_createKey($parameters),
					"lifetime" => 86400
				];

			return parent::find($parameters);
		}

		public static function getTreesRecursive($categoryId, $tree = []) {
			$category = self::findFirst($categoryId);

			$partLink = [];

			foreach(json_decode($category->parent_json, true) as $partParenJson)
				$partLink[] = $partParenJson['seoname'];

			$dataCategory = [
				'id' => $category->id,
				'seoname' => $category->seoname,
				'name' => $category->name,
				'link' => '/' . implode('/', $partLink)
			];

			if($category->child_ids == '[]') {
				$dataCategory['hasChild'] = false;
				$tree[] = $dataCategory;

				return $tree;
			}

			$dataCategory['hasChild'] = true;

			$last = NULL;

			foreach(self::find(['conditions' => "parent_id = {$category->id}"])->toArray() as $keyChildCategory => $childCategory) {
				if(in_array($childCategory['name'], ['Другое', 'Другие']))
					$last = self::getTreesRecursive($childCategory['id'], $tree);
				else {
					$dataCategory['childs'][$keyChildCategory] = self::getTreesRecursive($childCategory['id'], $tree);

					if(isset($dataCategory['childs'][$keyChildCategory][0]) && is_array($dataCategory['childs'][$keyChildCategory][0]) && count($dataCategory['childs'][$keyChildCategory]) == 1)
						$dataCategory['childs'][$keyChildCategory] = array_shift($dataCategory['childs'][$keyChildCategory]);
				}
			}

			if($last != NULL) {
				$dataCategory['childs'][$keyChildCategory] = $last;

				if(isset($last[0]) && is_array($last[0]) && count($last) == 1)
					$dataCategory['childs'][$keyChildCategory] = array_shift($last);
			}

			return $dataCategory;
		}

		public static function getTreeById($categoryId) {
			$keyForRedis = "categoryTree{$categoryId}";

			if(!$tree = RedisHelper::getJson($keyForRedis, true)) {
				$tree = self::getTreesRecursive($categoryId);

				RedisHelper::setJson($keyForRedis, $tree);
			}

			return $tree;
		}

		public static function getAllTrees() {
			$keyForRedis = "categoryTreesAll";

			if(!$trees = RedisHelper::getJson($keyForRedis, true)) {
				foreach(self::find(['conditions' => 'parent_id = 0'])->toArray() as $category) {
					$categoryTree = self::getTreeById($category['id']);
					$trees[] = $categoryTree;
				}

				RedisHelper::setJson($keyForRedis, $trees);
			}

			return $trees;
		}

		private static function getParentCategoryRecursive($parentId, $arr = false) {
			$category = self::findFirst($parentId);

			$arr[] = [
				'name' => $category->name,
				'seoname' => $category->seoname
			];

			if($category->parent_id != 0)
				return self::getParentCategoryRecursive($category->parent_id, $arr);

			return $arr;
		}

		// для генерации parent_json
		public static function generateParentJson() {
			$categories = self::find(['conditions' => 'add_json != \'null\'']);

			foreach($categories as $category) {
				$arr = [];
				$arr[] = [
					'name' => $category->name,
					'seoname' => $category->seoname
				];

				$category->parent_json = json_encode(array_reverse(self::getParentCategoryRecursive($category->parent_id, $arr)));

				try {
					if(!$category->save()) {
						echo "<pre>";
						var_dump($category->getMessages());
						echo "</pre>";

						exit();
					}
				} catch (\PDOException $e ) {
					echo "<pre>"; var_dump($e->getMessages); echo "</pre>";
					echo $category->parent_json;
					exit();
				}
			}
		}
	}

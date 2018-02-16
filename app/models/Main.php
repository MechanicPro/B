<?php
	namespace B\Modules\Advert\Models;

	use B\Library\Sphinx\ListAdvert;
  use Phalcon\Mvc\Model\Resultset\Simple as Resultset;
	use Phalcon\Mvc\Model\Transaction\Failed as TxFailed;
	use Phalcon\Mvc\Model\Transaction\Manager as TxManager;
	use Phalcon\DI\FactoryDefault;

	use B\Library\Image\RedisUploader;
	use B\Library\Validators\Advert\MainValidator;
	use B\Library\Error\Controller as Error;
	use B\Library\Lang\Controller as Lang;
	use B\Library\System\Controller as System;
	use B\Library\Logger\Controller as Logger;
  use B\Library\Helper\RedisHelper;
	use B\Library\Emails\Main as Email;
	use B\Library\Helper\Advert\RedefinitionDataHelper;

	use B\Modules\Profile\Models\User;
  use B\Modules\Advert\Models\Field;
	use B\Modules\Advert\Models\Stat\Job\TeachType as StatTeachType;
	use B\Modules\Advert\Models\Stat\Car\Mark;
	use B\Modules\Advert\Models\Stat\Car\Model;
	use B\Modules\Advert\Models\Stat\Year;

  use Foolz\SphinxQL\SphinxQL;
	use Foolz\SphinxQL\Connection as SphinxConnection;
	use B\Library\Sphinx\ClientShell as Sphinx;
	use B\Library\Helper\Advert\PriceHelper;

  use B\Library\Helper\Advert\IconHelper;

	class Main extends \Phalcon\Mvc\Model
	{
		private $validation;
		private $transaction;

		const FIELD_HASH_NAME = 'advert_hash';

		const STATUS_ACTIVE = 1;
		const STATUS_FINISHED = 2;
		const STATUS_BLOCKED = 3;
		const STATUS_REJECTED = 4;
		const STATUS_DELETED = 5;
		const STATUS_ON_MODERATION = 6;
		const STATUS_USER_FINISHED = 7;

		public static function getLink($data) {
			return "/{$data['city_seoname']}/{$data['category_seoname']}/{$data['seoname']}_{$data['id']}";
		}

    public function getSource() {
			return 'advert';
		}

		public function initialize() {
			$this->hasOne(
		    "category_id",
		    "B\\Modules\\Advert\\Models\\Category",
				"id", [
					"alias" => "category"
				]
		  );

			$this->hasOne(
		    "city_id",
		    "B\\Modules\\System\\Models\\Location",
				"id", [
					"alias" => "city"
				]
		  );

		  $this->hasOne(
	      "id",
	      "B\\Modules\\Profile\\Models\\Phone",
				"user_id", [
					"alias" => "phone"
				]
	    );

			$this->hasOne(
				"id",
				"B\\Modules\\Profile\\Models\\Social",
				"user_id", [
					"alias" => "social"
				]
			);
	  }

		private function beginTransaction() {
			$manager = new TxManager();

			$this->transaction = $manager->get();
		}

		private function rollbackTransaction() {
			try {
				$this->transaction->rollback();
			} catch (TxFailed $e) {}
		}

		private function commitTransaction() {
			$this->transaction->commit();
		}

		public function isAuthor($user_id) {
			if($this->user_id != $user_id)
				return false;

			return true;
		}

		private function generateSeoname() {
			$this->seoname = System::getTranslit($this->header);
		}
		
		private function getCustomRegionId() {
			$data = $this->validation->getData();

			if(isset($data['map_city_id']) && $data['map_city_id'] != 0)
				return $data['map_city_id'];

			if(isset($data['map_region_id']) && $data['map_region_id'] != 0)
				return $data['map_region_id'];

			return false;
		}	
			
		public function setDataModify() {
			if(isset($this->dt_modifies))
				if($dt_modifies = json_decode($this->dt_modifies, true))
					if(count($dt_modifies) > 20)
						array_shift($dt_modifies);

			$dt_modifies[] = time();

			$this->dt_modifies = json_encode($dt_modifies);
		}

		public function saveAll($data, $update = false) {
			$this->validation = new MainValidator();

			if(!$this->validation->validate($data))
				return $this->validation->getMessages();

			Logger::info('Подано объявление', ['Попытка подать объявление' => $this->validation->data]);

			$this->generateHeader();

			if(!$update) {
				$this->setValues();

				$this->generateSeoname();
			} else
				$this->status_id = self::STATUS_ON_MODERATION;

			$this->setDataModify();

			$this->beginTransaction();

			if(!$this->save()) {
				$this->rollbackTransaction();

				Logger::error('Объявление не сохранилось', ['errors' => $this->getMessages(), 'data' => $this->validation->data]);

				return ['system' => [Lang::get('error_system_unknown')]];
			}

			if($update)
				if($messages = $this->deleteAdditionals()) {
					$this->rollbackTransaction();

					return $messages;
				}

			if($messages = $this->saveAdditionals()) {
				$this->rollbackTransaction();

				return $messages;
			}

			if($messages = $this->savePhotos()) {
				$this->rollbackTransaction();

				return $messages;
			}

			$this->commitTransaction();

			$this->saveToSphinx();

			RedisHelper::delete($this->validation->data[self::FIELD_HASH_NAME]);

			return false;
		}

		public function updateAll($data) {
			$data['category_id'] = $this->category_id;

			return $this->saveAll($data, true);
		}

		public function saveAdditionals() {
			$models = $this->validation->getFieldsModels();
			$fields = $this->validation->getFields();
			$data = $this->validation->getData();

			foreach($fields as $nameField => $field) {
				$column = $field['field'];

				if(is_array($data[$nameField])) {
					foreach($data[$nameField] as $value) {
						if($value == '') 
							continue;

						$namespace = get_class($models[$nameField]);
						$model = new $namespace();

						if(isset($field['settings']))
							foreach(json_decode($field['settings'], true) as $settingsColumn => $setingsValue)
								$model->$settingsColumn = $setingsValue;

						$model->$column = $value;
						$model->advert_id = $this->id;
						$model->setTransaction($this->transaction);

						if(!$model->save()) {
							Logger::error('Объявление не сохранилось', ['data' => [
								'name' => $nameField,
								'messages' => $model->getMessages()
							]]);

							return ['system' => [Lang::get('error_system_unknown')]];
						}
					}
				} else {
					if($data[$nameField] == '') 
						continue;

					if(isset($field['settings']))
						foreach(json_decode($field['settings'], true) as $settingsColumn => $setingsValue)
							$models[$nameField]->$settingsColumn = $setingsValue;

					$models[$nameField]->$column = $data[$nameField];
					$models[$nameField]->advert_id = $this->id;
					$models[$nameField]->setTransaction($this->transaction);

					try {
						if(!$models[$nameField]->save()) {
							Logger::error('Объявление не сохранилось', ['data' => [
								'name' => $nameField,
								'messages' => $models[$nameField]->getMessages()
							]]);

							return ['system' => [Lang::get('error_system_unknown')]];
						}
					} catch (\PDOException $e) {						
					}

				}
			}

			return false;
		}

		public function deleteAdditionals() {
			$category = Category::findFirst($this->validation->data['category_id']);

			foreach(json_decode($category->add_json, true) as $obj)
				if(isset($obj['field_id']))
					if($field = Field::findFirst($obj['field_id']))
						foreach($field->model::find("advert_id = {$this->id}") as $value) {
							$value->setTransaction($this->transaction);
							$value->delete();
						}

			return false;
		}

		private function delCharFromText($value, $text) {
		  if (is_array($value)) {
        foreach ($value as $key => $item) {
          if (strcasecmp($key, $text) == 0) {
            if (isset($item)) {
              $value[$key] = self::santizeTextQuery($item);
            }
          }
        }
      }
      return $value;
    }

    private static function santizeTextQuery($value) {
      $value = htmlspecialchars($value);
      $value = preg_replace('/&(amp;)?(.+?);/', '', $value);
      $value = trim(preg_replace("/[^A-Za-zА-Яа-яЁё0-9# ]+?/u", "", $value));

      return $value;
    }

		public function saveToSphinx() {
			$db = FactoryDefault::getDefault()->getDb();
			$conn = FactoryDefault::getDefault()->getSphinx();

			if($data = $db->fetchOne("CALL get_advert_main_list({$this->id},{$this->id})")) {

			  $data = self::delCharFromText($data, 'header');

				try {
					$sq = SphinxQL::create($conn)->insert()->into('advert')->set($data)->execute();
				} catch(\Foolz\SphinxQL\Exception\DatabaseException $e) {
					try {
						$sq = SphinxQL::create($conn)->replace()->into('advert')->set($data)->execute();
					} catch(\Foolz\SphinxQL\Exception\DatabaseException $e) {
						Logger::error('Объявление не сохранилось', ['data' => $this->id]);
					}
				}
			}
		}

		public static function isValidId($id) {
			$conn = FactoryDefault::getDefault()->getSphinx();

			$sphinx = SphinxQL::create($conn)
					->select('id')
					->from('advert')
					->option('max_matches', 1)
					->limit(1)
					->where('id', '=', (int) $id);

			return !empty($sphinx->execute());
		}   

		public function sendEmailAdvertOnModeration() {
			$advert = $this->toArray();

			$advert['author'] = User::getFromRedis($advert['user_id'], ['email', 'first_name']);

			$dataList = [
				'id',
				'first_category_id',
				'category_id',
				'photos',
				'seoname',
				'city_name',
				'city_seoname',
				'category_seoname',
				'fields'
			];

			$data = Sphinx::getAdvertById($advert['id'], $dataList);

			$data['fields'] = json_decode($data['fields'], true);

			$advert['city_name'] = $data['city_name'];

			$advert['link'] = self::getLink($data);

			$data = RedefinitionDataHelper::getFormattedDataDetail($data);

			$advert['price'] = PriceHelper::getDescriptionFormattedPrice($data['fields']);

			if($photos = json_decode($data['photos']))
			 	$advert['photo'] = System::getLinkWithDomain(array_shift($photos) . '_min.jpg');
			else
			 	$advert['photo'] = IconHelper::getDefaultIcon($data['first_category_id'], $data['category_id']);

			Email::send(Email::NOTIFICATION, [
				'to' => $advert['author']['email'],
				'header' => 'Ваше объявление отправлено на модерацию',
				'template_dir' => 'notifications/item_create',
				'template_variables' => ['advert' => $advert]
			]);
		}
}

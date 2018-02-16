<?php
	namespace Belka\Modules\Advert\Models;

	use Belka\Library\Sphinx\ListAdvert;
  use Phalcon\Mvc\Model\Resultset\Simple as Resultset;
	use Phalcon\Mvc\Model\Transaction\Failed as TxFailed;
	use Phalcon\Mvc\Model\Transaction\Manager as TxManager;
	use Phalcon\DI\FactoryDefault;

	use Belka\Library\Image\RedisUploader;
	use Belka\Library\Validators\Advert\MainValidator;
	use Belka\Library\Error\Controller as Error;
	use Belka\Library\Lang\Controller as Lang;
	use Belka\Library\System\Controller as System;
	use Belka\Library\Logger\Controller as Logger;
  use Belka\Library\Helper\RedisHelper;
	use Belka\Library\Emails\Main as Email;
	use Belka\Library\Helper\Advert\RedefinitionDataHelper;

	use Belka\Modules\Profile\Models\User;
  use Belka\Modules\Advert\Models\Field;
	use Belka\Modules\Advert\Models\Stat\Job\TeachType as StatTeachType;
	use Belka\Modules\Advert\Models\Stat\Car\Mark;
	use Belka\Modules\Advert\Models\Stat\Car\Model;
	use Belka\Modules\Advert\Models\Stat\Year;

  use Foolz\SphinxQL\SphinxQL;
	use Foolz\SphinxQL\Connection as SphinxConnection;
	use Belka\Library\Sphinx\ClientShell as Sphinx;
	use Belka\Library\Helper\Advert\PriceHelper;

  use Belka\Library\Helper\Advert\IconHelper;

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
		    "Belka\\Modules\\Advert\\Models\\Category",
				"id", [
					"alias" => "category"
				]
		  );

			$this->hasOne(
		    "city_id",
		    "Belka\\Modules\\System\\Models\\Location",
				"id", [
					"alias" => "city"
				]
		  );

		  $this->hasOne(
	      "id",
	      "Belka\\Modules\\Profile\\Models\\Phone",
				"user_id", [
					"alias" => "phone"
				]
	    );

			$this->hasOne(
				"id",
				"Belka\\Modules\\Profile\\Models\\Social",
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

		private function generateHeader() {
			$data = $this->validation->getData();

			switch($data['category_id']) {
				case 16: // Легковые автомобили
					$mark = Mark::findFirst($data['car_mark_id']);
					$model = Model::findFirst($data['car_model_id']);
					$year = Year::findFirst($data['year_id']);

					$header = "{$mark->name} {$model->name}, {$year->name}";
				break;

				case 685:// Гаражи продам
					$area = number_format($data['building_area'], 0, '', ' ');
					$header = "Гараж, {$area} м²";
				break;
				case 693:// Гаражи, на длительный срок
					$area = number_format($data['building_area'], 0, '', ' ');
					$header = "Гараж, {$area} м²";
				break;
				case 694:// Гаражи, посуточно
					$area = number_format($data['building_area'], 0, '', ' ');
					$header = "Гараж, {$area} м²";
				break;

				case 687: // Дома, продам
					$area = number_format($data['building_area'], 0, '', ' ');
					$areaSection = number_format($data['building_area_section'], 0, '', ' ');

					$header = "Дом, $area м², на участке $areaSection сот.";
				break;
				case 695: // Дома, на длительный срок
					$area = number_format($data['building_area'], 0, '', ' ');
					$areaSection = number_format($data['building_area_section'], 0, '', ' ');

					$header = "Дом, $area м², на участке $areaSection сот.";
				break;
				case 696: // Дома, посуточно
					$area = number_format($data['building_area'], 0, '', ' ');
					$areaSection = number_format($data['building_area_section'], 0, '', ' ');

					$header = "Дом, $area м², на участке $areaSection сот.";
				break;

				case 689: // Квартиры продам
					if($data['building_count_room_id'] == 1)
						$header = 'Студия';
					else {
						$countRoom = $data['building_count_room_id'] - 1;
						$header = "$countRoom-к, Квартира";
					}

					$header .= ", " . number_format($data['building_area'], 0, '', ' ') . " м², {$data["building_floor"]}/{$data["building_count_floor"]} эт.";
				break;
				case 697: // Квартиры
					if($data['building_count_room_id'] == 1)
						$header = 'Студия';
					else {
						$countRoom = $data['building_count_room_id'] - 1;
						$header = "$countRoom-к, Квартира";
					}

					$area = number_format($data['building_area'], 0, '', ' ');

					$header .= ", {$area} м², {$data["building_floor"]}/{$data["building_count_floor"]} эт.";
				break;
				case 698: // Квартиры
					if($data['building_count_room_id'] == 1)
						$header = 'Студия';
					else {
						$countRoom = $data['building_count_room_id'] - 1;
						$header = "$countRoom-к, Квартира";
					}

					$area = number_format($data['building_area'], 0, '', ' ');

					$header .= ", {$area} м², {$data["building_floor"]}/{$data["building_count_floor"]} эт.";
				break;

				case 691: // Комнаты, продам
					$area = number_format($data['building_area'], 0, '', ' ');

					$header = "в {$data['building_count_room_id']}-к, Комната, {$area} м², {$data["building_floor"]}/{$data["building_count_floor"]}";
				break;
				case 699: // Комнаты, на длительный срок
					$area = number_format($data['building_area'], 0, '', ' ');

					$header = "в {$data['building_count_room_id']}-к, Комната, {$area} м², {$data["building_floor"]}/{$data["building_count_floor"]}";
				break;
				case 700: // Комнаты, посуточно
					$area = number_format($data['building_area'], 0, '', ' ');

					$header = "в {$data['building_count_room_id']}-к, Комната, {$area} м², {$data["building_floor"]}/{$data["building_count_floor"]}";
				break;

				case 147: // Участки
					$areaSection = number_format($data['building_area_section'], 0, '', ' ');

					$header = "Участок {$areaSection} сот.";
				break;

				case 148: // Машиноместо
					$areaSection = number_format($data['building_area_section'], 0, '', ' ');

					$header = "Машиноместо {$areaSection} м²";
				break;

				case 178:
					$header = "Резюме: {$data['job_post']}";
				break;

				case 179: // Вакансии
					$header = "{$data['job_post']}";
				break;

				case 180:
					$type =	StatTeachType::findFirst($data['job_teach_type_id']);

					$header = "{$type->name}: {$data['job_surname']} {$data['job_name']}";

					if(isset($data['job_patronimic']))
						$header .= " {$data['job_patronimic']}";

				break;
				default:
					$header = $data['header'];
			}

			$this->header = $header;
		}

		private function getCustomRegionId() {
			$data = $this->validation->getData();

			if(isset($data['map_city_id']) && $data['map_city_id'] != 0)
				return $data['map_city_id'];

			if(isset($data['map_region_id']) && $data['map_region_id'] != 0)
				return $data['map_region_id'];

			return false;
		}

		public function setValues() {
			$userId = User::getUserIdFromSession();
			$user = User::getFromRedis($userId, ['city_id']);

			$this->category_id = $this->validation->data['category_id'];
			$this->user_id = $userId;
			$this->dt_create = time();
			$this->dt_sort = $this->dt_create;
			$this->status_id = self::STATUS_ON_MODERATION;
			$this->dt_expired = $this->dt_create + FactoryDefault::getDefault()->getConfig()->advert->lifetime;

			if($cityId = $this->getCustomRegionId())
				$this->city_id = $cityId;
			else
				$this->city_id = $user['city_id']; // по умолчанию исполюзуется id города пользователя
		}

		public function savePhotos() {
			$uploader = new RedisUploader();

			$photos = $uploader->write(
				$this->validation->data[self::FIELD_HASH_NAME], [
					'advert_id' => $this->id,
					'dt_create' => $this->dt_create
				]
			);

			if(is_array($photos)) {
				if(empty($photos))
					$this->photos = '';
				else
					$this->photos = json_encode($photos);

				if(!$this->save()) {
					$this->rollbackTransaction();
					Logger::error('Объявление не сохранилось', ['data' => $this->getMessages()]);

					return ['system' => [Lang::get('error_system_unknown')]];
				}
			}

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
						if($value == '') // если пустое поле, то пропускаем это поле
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
					if($data[$nameField] == '') // если пустое поле, то пропускаем это поле
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
						// echo $models[$nameField]->value;
						// echo '<br>';
						// echo '<br>';
						// echo $e->getMessage();
						// echo 'err';
						//exit();
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

    public function afterSave() {
			$changedAdverts = RedisHelper::getJson('belka_adverts_what_be_changed_till_reindexation', true);

			if(is_null($changedAdverts))
				return false;

			if(in_array($this->id, $changedAdverts))
				return false;

			$changedAdverts[] = $this->id;

			RedisHelper::setJson('belka_adverts_what_be_changed_till_reindexation', $changedAdverts);
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

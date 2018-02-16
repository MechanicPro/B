<?php
	namespace B\Modules\Advert\Controllers;

	use B\Library\Lang\Controller as Lang;
	use B\Library\Error\Controller as Error;
	use B\Library\System\Controller as System;
	use B\Library\Logger\Controller as Logger;
	use B\Library\Helper\Advert\CreateHelper;
	use B\Library\Helper\Advert\HashHelper as AdvertHashHelper;
	use B\Library\Image\RedisUploader;
	use B\Library\Captcha\Controller as Captcha;
	use B\Modules\Advert\Models\Category;
	use B\Modules\Advert\Models\Main as Advert;

	class CreateController extends \Phalcon\Mvc\Controller
	{
		public function successAction() { }

		public function addAction() {
			System::redirectTo('create');
		}

		public function categoryAction() {
			if(!$catId = (int) $this->dispatcher->getParam('catId'))
				System::RedirectTo('404');

			$uploader = new RedisUploader();

			if(!$uploader->deleteAllImages($_GET))
				return $uploader->getJsonErrors();

			try {
				$categories = Category::find([
					"par_id = :id:",
					'bind' => ['id' => $catId],
					'columns' => ['id','name'],
					'order' => ['name']
				]);
			} catch (\PDOException $e) {
				Logger::error('Ошибка бд', ['data' => $e->getMessages()]);

				return Error::getJson('system_unknown');
			}

			if(count($categories))
				return json_encode(['categories' => $categories]);

			$category = Category::findFirst($catId);

			$this->view->setVar('catId', $catId);
 			$this->view->setVar('fields', CreateHelper::getFormattedFields(json_decode($category->add_json, true)));

			return json_encode(['content' => $this->view->getRender('create', 'category')]);
		}

		public function createAction() {
			$captcha = new Captcha();

			if($this->request->isPost()) {
				$captcha->setHash($this->request->get('captcha_hash'));
				$captcha->setText($this->request->get('captcha_text'));

				if(!$captcha->check())
					return json_encode([
						'errors' => [
							'captcha_text' => [Lang::get('error_captcha_wrong')]
						],
						'captcha_image' => $captcha->getImage(),
						'captcha_hash' => $captcha->getHash()
					]);

				$advert = new Advert();

				if($errors = $advert->saveAll($_POST)) {
					return json_encode([
						'errors' => $errors,
						'captcha_image' => $captcha->getImage(),
						'captcha_hash' => $captcha->getHash()
					]);
				}

				$advert->sendEmailAdvertOnModeration();

				return json_encode([
					'id' => $advert->id,
					'success' => true
				]);
			}

 			$this->view->setVar('advert_hash', AdvertHashHelper::generate());
			$this->view->setVar('captcha_hash', $captcha->getHash());
			$this->view->setVar('captcha_image', $captcha->getImage());
			$this->view->setVar('categories', Category::find([
				'columns' => ['id', 'name'],
				'parent_id = 0',
				'order' => ['name'] ]
			)->toArray());
    }

		public function imageRotateAction() {
			$uploader = new RedisUploader();

			if(!$response = $uploader->rotate($_POST))
				return $uploader->getJsonErrors();

			return $response;
		}

		public function imageAppendAction() {
			$uploader = new RedisUploader();

			if(!$response = $uploader->append($_POST, $_FILES))
				return $uploader->getJsonErrors();

			return $response;
		}

		public function imageSwapAction() {
			$uploader = new RedisUploader();

			if(!$response = $uploader->swap($_POST))
				return $uploader->getJsonErrors();

			return $response;
		}

		public function imageDeleteAction() {
			$uploader = new RedisUploader();

			if(!$response = $uploader->delete($_POST))
				return $uploader->getJsonErrors();

			return $response;
		}
	}

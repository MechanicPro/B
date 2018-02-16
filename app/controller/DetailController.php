<?php
	namespace B\Modules\Advert\Controllers;

	use B\Library\System\Controller as System;
	use B\Library\Sphinx\ClientShell as SphinxClient;
	use B\Library\Builder\Advert\DetailAdvert as DetailAdvertBuilder;
	use B\Modules\Advert\Models\Watched;
	use B\Modules\Advert\Models\Main as Advert;
	use B\Modules\Seo\Models\Main as Seo;
	use B\Modules\Profile\Models\User;

	class DetailController extends \Phalcon\Mvc\Controller
	{
    public function showAction() {
			if(!$advertId = (int) $this->dispatcher->getParam('id'))
				System::RedirectTo('404');

			$advert = new DetailAdvertBuilder();

			if(!$advert->findById($advertId))
        System::RedirectTo('404');

			$advert->appendUrlParam('city', $this->dispatcher->getParam('city'));
			$advert->appendUrlParam('category', $this->dispatcher->getParam('category'));
			$advert->appendUrlParam('seoname', $this->dispatcher->getParam('seoname'));

			if(!$advert->checkSeonameParams())
				$this->response->redirect($advert->getLinkToAdvert());

      $advert->addAdditional(['category', 'similars', 'interestings']);

			$data = $advert->getData();

			$this->view->setVar('status_id', $data['status_id']);

			if(!in_array($data['status_id'], [ 
					Advert::STATUS_ACTIVE,
					Advert::STATUS_FINISHED
			])) {
				if(!$userId = (int) User::getUserIdFromSession()) {
					$this->view->pick('advert/detail/denied');

					return true;
				}

				$user =	User::getFromRedis($userId, ['role_id']);

				if(!in_array($user['role_id'], [
					User::ROLE_ADMIN,
					User::ROLE_MODERATOR,
					User::ROLE_SEO
				])) {
					$this->view->pick('advert/detail/denied');

					return true;
				}
			}

			$this->view->setVars($data); 

			if($seoData = Seo::getDetail($data))
				$this->view->setVars($seoData);

			Watched::add($advertId);
		}

		public function oldAction() {
			$data = SphinxClient::getAdvertsByIds([(int) $this->dispatcher->getParam('id')], ['city_seoname', 'category_seoname', 'seoname', 'id']);
			if (isset($data[0])) {
        $link = Advert::getLink($data[0]);
        $this->response->redirect($link, true, 301);
      }
		}
	}

<?php
	namespace B\Modules\Advert\Models\Field;

	class Content extends \Phalcon\Mvc\Model
	{
		public function getSource() {
			return 'field__content';
		}

		public $validators = [
			'value' => [
				'\B\Library\Validators\Adv\NotEmptyString' => [],
				'StringLength' => [
                    'max' => 5000,
					'messageMaximum' => "Cлишком длинный текст"
                ]
			]
		];

		public $filters = [
			'value' => [
				'trim',
				'\B\Library\Filter\HtmlEntities',
				'\B\Library\Filter\ReplaceEndLineOnBr',
				'\B\Library\Filter\DelCharMore4Bytes'
			]
		];
    }

{% set bodyClass = 'page-create' %}

{% include "system/default/header.volt" %}

<div class="container create-wrp">
  <div class="create">
    <div class="create__wait">
      <div class="loader-whirlpool"></div>
    </div>

    <form class="create__form" onsubmit="Create.start(this, event);" novalidate>
      <input type="hidden" name="advert_hash" value="{{ advert_hash }}">
      <h1 class="create__title">Подать объявление</h1>

      <!--PROFILE INFO-->
      <!--# include virtual="/ssi/info" -->

      <!--CATEGORY PICKER-->
      <input type="hidden" name="category_id" required>
      <div class="category-picker">
        <div class="category-picker__breadcrumbs">
          <h2 class="category-picker__breadcrumbs-title">Выберите категорию</h2>
          <div class="category-picker__breadcrumbs-text"></div>
          <div class="category-picker__breadcrumbs-change">Изменить</div>
        </div>
        <div class="category-picker__side .category-picker__side_first">
          <div class="category-picker__title">Категория</div>
          <div class="category-picker__categories">
            {% set mCategoryOptions = [
              [
                'value': 0,
                'name': 'Выберите категорию',
                'selected': true
              ]
            ] %}

            {% for category in categories %}
              <?php
                array_push($mCategoryOptions, [
                  'value' => $category['id'],
                  'name' => $category['name'],
                  'selected' => false
                ]);
              ?>
              <div class="category-picker__category" data-category-id="{{ category['id'] }}">{{ category['name'] }}</div>
            {% endfor %}
          </div>
        </div>
      </div>

      <div class="m-category-picker">
        {{ partial('advert/fields/select', [
          'label': 'Категория',
          'options': mCategoryOptions,
          'selectWrpClasses': ['fieldset-select__wrp_right-arrow'],
          'selectClasses': ['m-category-picker__category']
        ]) }}
      </div>

      <div class="create__fields"></div>

			{{ partial('advert/fields/captcha', [
				'label': '',
				'captcha_hash': captcha_hash,
				'captcha_image': captcha_image,
				'required': true
			]) }}

      <div class="create__submit">
        <button class="header__new-item button button_color_orange button_align_middle button_rounded" type="submit">Подать объявление</button>
      </div>
    </form>
  </div>
</div>

{% include "system/default/footer.volt" %}

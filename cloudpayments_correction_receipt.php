<?php

require './functions.php';


$property = [
    'method' => [
      // Метод получения детализации чека
      'get_details' => 'https://api.cloudpayments.ru/kkt/receipt/get',
      // Метод формирования чека коррекции
      'set_correction_receipt' => 'https://api.cloudpayments.ru/kkt/correction-receipt',
    ],
    // Список касс, которым потенциально принадлежат чеки
    'api_key' => [
        'pk_key1' => 'secret',
        'pk_key2' => 'secret',
        'pk_key3' => 'secret',
    ],
    'content' => [
        'receipt_id' => [],
        'receipt_url' => [],
        'receipt_data' => [],
    ],
    // Элементы с которыми будет проводиться какая-то работа
    'filter' => [
        'access' => [],
        'not_access' => [],
    ],
];

// Считываем csv
foreach (parse_csv('example.csv') as $key => $value) {
  // Предпросмотр в браузере
  if ($key <= 2) view_display($value);

  if ($value[8] !== 'Ссылка на чек') {
    $property['content']['receipt_id'][] = trim(str_replace('https://receipts.ru/', '', $value[8]));
    $property['content']['receipt_url'][] = trim($value[8]);
  }
}

view_display('Чеков в файле '.count($property['content']['receipt_id']));

// Получить содержимое чека
$countForLog = count($property['content']['receipt_id']);
foreach ($property['content']['receipt_id'] as $key => $id) {
  // Заранее нам неизвестно, какой именно кассе принадлежит чек,
  // поэтому пробуем получить данные проходясь по каждой кассе.
  foreach ($property['api_key'] as $apiKey => $apiPass) {
    $result = send($property['method']['get_details'], ['Login' => $apiKey, 'Password' => $apiPass], ['Id' => $id])['result'];
    $result = json_decode($result, true);

    if ($result['Success']) {
      $property['content']['receipt_data'][] = [
          'receipt_id' => $id, 'receipt_url' => $property['content']['receipt_url'][$key], 'value' => $result,
      ];

      break;
    }
  }

  if (($countForLog - 1) == $key || (($key + 1) % 20) == 0) { ?>
    <script id="log">
      console.log('Обработано ' + <?= $key + 1 ?> + ' из ' + <?= $countForLog ?>);
      document.querySelectorAll('#log').forEach((index) => {
        index.remove();
      });
    </script>
    <?php
  }
}

view_display('Получено содержимое '.count($property['content']['receipt_data']).' чеков');

// Список чеков, которые не принадлежат ни одной из перечисленных касс
foreach ($property['content']['receipt_id'] as $key => $id) {
  $tempResult = false;

  foreach ($property['content']['receipt_data'] as $item => $itemValue) {
    // Идентификаторы чеков чувствительны к регистру
    if ($id === $itemValue['receipt_id']) {
      $tempResult = true;
      break;
    }
  }

  if (!$tempResult) {
    $property['filter']['not_access'][] = [
        'receipt_id' => $id, 'receipt_url' => $property['content']['receipt_url'][$key],
    ];
  }
}

if (!empty($property['filter']['not_access'])) {
  view_display_in_spoiler(
      'Не удалось загрузить содержимое чеков '.count($property['filter']['not_access']), $property['filter']['not_access']
  );

  export_csv('error', 'csv', $property['filter']['not_access'], true);
}

// Выбираем элемент(ы) для чек коррекции
$searchValue = ['Доставка'];
foreach ($property['content']['receipt_data'] as $key => $value) {
  $check = $value['value'];

  // Проверяем каждый товар в чеке
  $countDuplicate = 0;
  foreach ($check['Model']['Items'] as $item) {
    foreach ($searchValue as $index => $name) {
      if (strpos(mb_strtolower($name), trim(mb_strtolower(htmlentities($item['Label']), "UTF-8"))) !== false) {
        $countDuplicate++;

        if ($index > 0) {
          break;
        }
      }
    }
  }

  if ($countDuplicate > 1) {
    $property['filter']['access'][] = $value;
  }
}

view_display_in_spoiler('Отфильтрованные элементы '.'('.count($property['filter']['access']).')', $property['filter']['access']);

export_csv('result', 'csv', $property['filter']['access']);

correction_receipt($property, $searchValue);

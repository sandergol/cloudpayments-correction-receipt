<?php

/*
 * TODO: Будьте осторожны при попытке переиспользовать эти функции,
 *       изначально они под это не предназначались.
 * */

// Отправляем запрос на чек коррекцию
function correction_receipt($property, $searchValue) {
    foreach ($property['filter']['access'] as $key => $value) {
        // $id = $value['receipt_id'];
        // $url = $value['receipt_url'];
        $check = $value['value'];

        // Определяем способ оплаты
        $payMethod = '';
        foreach ($check['Model']['Amounts'] as $method => $sum) {
            if ($method == 'Sum') continue; // Пропускаем поле с итоговой суммой

            if ($sum > 0) {
                $payMethod = $method;
                break;
            }
        }

        if (empty($payMethod)) return 'Не определен метод оплаты.';

        // Вычисляем сумму для коррекции
        $resultSumCorrection = (double)$check['Model']['Amounts'][$payMethod];

        $tempValue = [
            'delivery' => [],
        ];

        foreach ($check['Model']['Items'] as $product) {
            if ($product['Label'] == $searchValue[0])
                $tempValue['delivery'][] = $product;
        }

        // Удаляем дубликаты доставки
        $countDelivery = count($tempValue['delivery']) - 1;
        foreach ($tempValue['delivery'] as $currentCount => $item) {
            if ($currentCount < $countDelivery)
                $resultSumCorrection = (double)($resultSumCorrection - $tempValue['delivery'][$currentCount]['Amount']);
        }

        if ($resultSumCorrection <= 0)
            return "Сумма коррекции должна быть больше нуля. Текущее значение \"$resultSumCorrection\"";

        $request = [
            'CorrectionReceiptData' => [
                'OrganizationInn' => (string)$check['Model']['AdditionalData']['OrganizationInn'],
                'VatRate' => 6, // 6 = Без НДС
                'TaxationSystem' => 2, // 2 = Упрощенная система налогообложения (Доход минус Расход)
                'DeviceNumber' => (string)$check['Model']['AdditionalData']['DeviceNumber'],
                'CorrectionReceiptType' => 1, // 1 = Корректировка прихода
                'CauseCorrection' => [
                    'CorrectionDate' => date('Y-m-d'),
                    'CorrectionNumber' => $check['Model']['AdditionalData']['DocumentNumber'],
                ],
                'Amounts' => [
                    (string)$payMethod => round((double)$resultSumCorrection, 2),
                ],
            ],
        ];

        /*
         * TODO: Раскомментируйте после того, как убедитесь, что в кассу будут переданы только корректные данные.
         * */

        // Отправляем запрос на коррекцию чека проходясь по каждой доступной кассе.
        // Если чек не принадлежит какой-то из касс – чек коррекция не будет произведена.
        /*
        foreach ($property['api_key'] as $apiKey => $apiPass) {
            $result = send($property['method']['set_correction_receipt'], ['Login' => $apiKey, 'Password' => $apiPass], $request)['result'];
            $result = json_decode($result, true);

            if ($result['Success']) {
                echo json_encode($request);
                view_display($result);

                break;
            }
        }
        */
    }
}

function parse_csv($file_path, $file_encodings = ['cp1251', 'UTF-8'], $col_delimiter = '', $row_delimiter = "") {
    if (!file_exists($file_path)) {
        return false;
    }

    // Конвертируем кодировку в UTF-8
    $cont = trim(file_get_contents($file_path));
    $encoded_cont = mb_convert_encoding($cont, 'UTF-8', mb_detect_encoding($cont, $file_encodings));
    unset($cont);

    // Определим разделитель
    if (!$row_delimiter) {
        $row_delimiter = "\r\n";

        if (strpos($encoded_cont, "\r\n") === false) {
            $row_delimiter = "\n";
        }
    }

    // Очищаем массив от пустых значений
    $lines = explode($row_delimiter, trim($encoded_cont));
    $lines = array_filter($lines);
    $lines = array_map('trim', $lines);

    // Определяем разделитель из двух возможных: ';' или ','.
    // для расчета берем не больше 100 строк
    if (!$col_delimiter) {
        $separator = array_slice($lines, 0, 100);

        foreach ($separator as $line) {
            if (!strpos($line, ',')) {
                $col_delimiter = ';';
            }
            if (!strpos($line, ';')) {
                $col_delimiter = ',';
            }
            if ($col_delimiter) {
                break;
            }
        }

        // если первый способ не дал результатов, то погружаемся в задачу и считаем кол разделителей в каждой строке.
        // где больше одинаковых количеств найденного разделителя, тот и разделитель.
        if (!$col_delimiter) {
            $delim_counts = [';' => [], ',' => []];
            foreach ($separator as $line) {
                $delim_counts[','][] = substr_count($line, ',');
                $delim_counts[';'][] = substr_count($line, ';');
            }

            $delim_counts = array_map('array_filter', $delim_counts); // уберем нули

            // кол-во одинаковых значений массива - это потенциальный разделитель
            $delim_counts = array_map('array_count_values', $delim_counts);
            $delim_counts = array_map('max', $delim_counts); // берем только макс. значения вхождений

            if ($delim_counts[';'] === $delim_counts[',']) {
                return ['Не удалось определить разделитель колонок.'];
            }

            $col_delimiter = array_search(max($delim_counts), $delim_counts);
        }
    }

    $data = [];
    foreach ($lines as $key => $line) {
        $data[] = str_getcsv($line, $col_delimiter);
        unset($lines[$key]);
    }

    return $data;
}

function send($url, $access, $data): array {
    $request_data = [
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_POST => 1,
        CURLOPT_HEADER => 0,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_USERPWD => $access['Login'].":".$access['Password'],
    ];

    $curl = curl_init();
    curl_setopt_array($curl, $request_data);

    $result = curl_exec($curl);

    curl_close($curl);

    return [
        "result" => $result,
    ];
}

// Выводим прогресс на экран
function view_display($data) {
    echo '<pre>';
    var_export($data);
    echo '</pre>';
}

function view_display_in_spoiler($name, $data) {
    echo '<details>';
    echo "<summary>$name</summary>";
    echo '<pre>';
    var_export($data);
    echo '</pre>';
    echo '</details>';
}

// Запись результата в файл
function export_csv($file_path, $format, $content, $isError = false) {
    if (!empty($content['value']))
        $content = $content['value'];

    if (empty($content)) {
        view_display('Отсутствует содержимое для записи');

        return false;
    }

    $out = fopen(getFileName($file_path, $format), 'a+');

    if ($isError) {
        fputcsv($out, ['ID', 'Ссылка на чек']);
    } else {
        fputcsv($out, ['ID', 'Ссылка на чек', 'Сумма чека', 'Дата чека', 'Номер заказа', 'Чек целиком']);
    }

    foreach ($content as $item) {
        $result = [
            // Обязательные поля
            0 => (string)$item['receipt_id'],
            1 => (string)$item['receipt_url'],
            // Не обязательные поля
            2 => (string)$item['receipt_data']['Model']['Amounts']['Sum'] ?: '',
            3 => (string)$item['receipt_data']['Model']['AdditionalData']['DateTime'] ?: '',
            4 => (string)$item['receipt_data']['Model']['AdditionalData']['InvoiceId'] ?: '',
            5 => serialize($item['receipt_data']) != 'N' ? serialize($item['receipt_data']) : '',
        ];

        fputcsv($out, $result);
    }

    fclose($out);
}

function getFileName($file_path, $format): string {
    $count = 1;
    $fullName = $file_path.'.'.$format;

    while (file_exists($fullName)) {
        $fullName = $file_path.'('.$count.')'.'.'.$format;
        $count++;
    }

    return $fullName;
}

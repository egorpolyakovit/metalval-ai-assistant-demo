<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

$root = dirname(__DIR__);
$storageDir = $root . '/storage';
$sessionDir = $storageDir . '/sessions';
$faqPath = $root . '/data/faq.example.json';
$leadCsv = $storageDir . '/leads.csv';

if (!is_dir($sessionDir)) {
    @mkdir($sessionDir, 0775, true);
}

function mvReply(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mvNorm(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = str_replace('ё', 'е', $value);
    $value = preg_replace('/\s+/u', ' ', $value);
    return trim((string)$value);
}

function mvLoadJson(string $path, array $fallback = []): array
{
    if (!is_file($path)) return $fallback;
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : $fallback;
}

function mvSaveJson(string $path, array $data): void
{
    file_put_contents(
        $path,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

function mvSafeSessionId(string $value): string
{
    return preg_match('/^[a-f0-9]{32}$/', $value) ? $value : '';
}

function mvNewSessionId(): string
{
    return bin2hex(random_bytes(16));
}

function mvExtractDimensions(string $text): array
{
    $items = [];

    preg_match_all(
        '/(?:(\d+)\s*(?:шт\.?|штук[аи]?|люк(?:а|ов)?)\s*)?(\d{2,5})\s*[xх×]\s*(\d{2,5})(?:\s*[xх×]\s*(\d{2,5}))?(?:\s*мм)?(?:[^;\n,]*?\bRAL\s*[-:]?\s*(\d{3,4}))?/iu',
        $text,
        $matches,
        PREG_SET_ORDER
    );

    foreach ($matches as $m) {
        $qty = isset($m[1]) && $m[1] !== '' ? (int)$m[1] : 1;
        $dims = $m[2] . '×' . $m[3] . (!empty($m[4]) ? '×' . $m[4] : '') . ' мм';

        $items[] = [
            'quantity' => $qty,
            'dimensions' => $dims,
            'ral' => !empty($m[5]) ? 'RAL ' . $m[5] : ''
        ];
    }

    return $items;
}

function mvHasNoDrawing(string $text): bool
{
    return (bool)preg_match(
        '/нет.*чертеж|чертеж.*нет|без.*чертеж|чертежа не име|чертеж отсутств/u',
        mvNorm($text)
    );
}

function mvHasProjectDocs(string $text): bool
{
    return (bool)preg_match(
        '/проектн.*документ|техническ.*задан|(^|\s)тз(\s|$)|есть.*проект|проект.*есть/u',
        mvNorm($text)
    );
}

function mvIsPositive(string $text): bool
{
    return (bool)preg_match('/^(да|ага|угу|конечно|хорошо|ок|окей)\b/u', mvNorm($text));
}

function mvIsNegative(string $text): bool
{
    return (bool)preg_match('/^(нет|не надо|не нужно|пока нет)\b/u', mvNorm($text));
}

function mvIsTimingQuestion(string $text): bool
{
    return (bool)preg_match(
        '/сколько.*ждать.*ответ|как долго.*ждать.*ответ|как скоро.*ответ|когда.*ответ|когда.*свяж|как скоро.*свяж|долго.*менеджер/u',
        mvNorm($text)
    );
}

function mvDetectClientType(string $text): string
{
    $v = mvNorm($text);
    if (preg_match('/юр(?:идическ)?|ооо|ао|пао|зао|ип/u', $v)) return 'legal';
    if (preg_match('/физ(?:ическ)?|частн/u', $v)) return 'physical';
    return '';
}

function mvValidName(string $text): bool
{
    $text = trim((string)preg_replace('/[.,]/u', ' ', $text));
    $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

    if (count($words) < 2 || count($words) > 4) return false;

    foreach ($words as $word) {
        if (!preg_match('/^[А-ЯЁA-Z][А-Яа-яЁёA-Za-z-]{1,40}$/u', $word)) {
            return false;
        }
    }

    return true;
}

function mvValidContact(string $text): bool
{
    $text = trim($text);

    if (filter_var($text, FILTER_VALIDATE_EMAIL)) {
        return true;
    }

    $digits = preg_replace('/\D+/', '', $text);
    return strlen($digits) >= 10 && strlen($digits) <= 15;
}

function mvMatchFaq(string $text, array $faq): ?array
{
    $v = mvNorm($text);
    $best = null;
    $bestScore = 0;

    foreach ($faq as $row) {
        $score = 0;

        foreach (($row['keywords'] ?? []) as $keyword) {
            $k = mvNorm((string)$keyword);

            if ($k !== '' && mb_strpos($v, $k, 0, 'UTF-8') !== false) {
                $score++;
            }
        }

        if ($score > $bestScore) {
            $best = $row;
            $bestScore = $score;
        }
    }

    return $bestScore > 0 ? $best : null;
}

function mvSaveLead(string $leadCsv, array $state): void
{
    $isNew = !is_file($leadCsv);
    $fp = fopen($leadCsv, 'ab');

    if (!$fp) return;

    if ($isNew) {
        fputcsv($fp, [
            'created_at',
            'company',
            'city',
            'contact_name',
            'position',
            'contact',
            'product',
            'request'
        ]);
    }

    $request = [];

    foreach (($state['items'] ?? []) as $item) {
        $row = ($item['quantity'] ?? 1) . ' шт. ' . ($item['dimensions'] ?? '');

        if (!empty($item['ral'])) {
            $row .= ' ' . $item['ral'];
        }

        $request[] = trim($row);
    }

    fputcsv($fp, [
        date('c'),
        $state['company'] ?? '',
        $state['city'] ?? '',
        $state['contact_name'] ?? '',
        $state['position'] ?? '',
        $state['contact'] ?? '',
        $state['product_title'] ?? '',
        implode('; ', $request)
    ]);

    fclose($fp);
}

$raw = file_get_contents('php://input');
$data = json_decode((string)$raw, true);

if (!is_array($data)) {
    mvReply(['error' => 'Invalid JSON'], 400);
}

$action = (string)($data['action'] ?? '');
$sessionId = mvSafeSessionId((string)($data['session_id'] ?? ''));

if ($sessionId === '') {
    $sessionId = mvNewSessionId();
}

$sessionPath = $sessionDir . '/' . $sessionId . '.json';

$state = mvLoadJson($sessionPath, [
    'step' => 'ask_name',
    'name' => '',
    'items' => [],
    'company' => '',
    'city' => '',
    'contact_name' => '',
    'position' => '',
    'contact' => '',
    'product_title' => ''
]);

$context = is_array($data['context'] ?? null) ? $data['context'] : [];
$product = is_array($context['product'] ?? null) ? $context['product'] : [];

if (!empty($product['title'])) {
    $state['product_title'] = (string)$product['title'];
}

if ($action === 'start') {
    mvSaveJson($sessionPath, $state);

    mvReply([
        'session_id' => $sessionId,
        'answer' => 'Как я могу к Вам обращаться?',
        'time' => date('H:i:s')
    ]);
}

if ($action !== 'message') {
    mvReply(['error' => 'Unsupported action'], 400);
}

$userText = trim((string)($data['message'] ?? ''));

if ($userText === '') {
    mvReply(['error' => 'Empty message'], 422);
}

$faq = mvLoadJson($faqPath, []);

if ($state['step'] === 'ask_name') {
    $state['name'] = preg_replace('/^(я\s+|меня\s+зовут\s+)/iu', '', $userText);
    $state['name'] = trim((string)$state['name'], " \t\n\r\0\x0B.!?,");
    $state['step'] = 'main_question';

    mvSaveJson($sessionPath, $state);

    mvReply([
        'session_id' => $sessionId,
        'answer' => 'Добрый день, ' . $state['name'] . '. Чем могу Вам помочь?',
        'time' => date('H:i:s')
    ]);
}

$detectedItems = mvExtractDimensions($userText);

if ($detectedItems) {
    $state['items'] = $detectedItems;
}

if ($state['step'] === 'main_question' && $detectedItems) {
    $state['step'] = 'ask_drawing_status';

    $summary = [];

    foreach ($detectedItems as $item) {
        $s = $item['quantity'] . ' шт. — ' . $item['dimensions'];

        if ($item['ral'] !== '') {
            $s .= ', ' . $item['ral'];
        }

        $summary[] = $s;
    }

    mvSaveJson($sessionPath, $state);

    mvReply([
        'session_id' => $sessionId,
        'answer' =>
            "Зафиксировал параметры:\n" .
            implode("\n", $summary) .
            "\n\nПодскажите, пожалуйста, у Вас есть чертежи, техническое задание или проектная документация на эти люки?",
        'time' => date('H:i:s')
    ]);
}

if ($state['step'] === 'ask_drawing_status') {
    if (mvHasNoDrawing($userText) && mvHasProjectDocs($userText)) {
        $state['step'] = 'confirm_lead';
        mvSaveJson($sessionPath, $state);

        mvReply([
            'session_id' => $sessionId,
            'answer' =>
                "Для предварительного рассмотрения проектной документации достаточно.\n\n" .
                "Я могу зафиксировать Ваш запрос и передать его менеджеру для дальнейшего расчёта. Зафиксировать?",
            'time' => date('H:i:s')
        ]);
    }

    if (mvHasNoDrawing($userText)) {
        $state['step'] = 'confirm_lead';
        mvSaveJson($sessionPath, $state);

        mvReply([
            'session_id' => $sessionId,
            'answer' =>
                "Чертёж на первом этапе не обязателен. Для предварительной оценки можно использовать размеры и описание изделия.\n\n" .
                "Зафиксировать запрос для менеджера?",
            'time' => date('H:i:s')
        ]);
    }

    if (mvIsPositive($userText) || mvHasProjectDocs($userText)) {
        $state['step'] = 'confirm_lead';
        mvSaveJson($sessionPath, $state);

        mvReply([
            'session_id' => $sessionId,
            'answer' => 'Отлично. Зафиксировать Ваш запрос для передачи менеджеру?',
            'time' => date('H:i:s')
        ]);
    }
}

if ($state['step'] === 'confirm_lead') {
    if (mvIsTimingQuestion($userText)) {
        $answer = 'Запрос передается менеджеру отдела продаж. Точное время ответа зависит от загрузки и времени поступления заявки.';

        if (mvIsPositive($userText)) {
            $state['step'] = 'ask_client_type';
            $answer .= "\n\nЗапрос зафиксируем. Подскажите, пожалуйста, Вы обращаетесь как физическое или юридическое лицо?";
        }

        mvSaveJson($sessionPath, $state);

        mvReply([
            'session_id' => $sessionId,
            'answer' => $answer,
            'time' => date('H:i:s')
        ]);
    }

    if (mvIsPositive($userText)) {
        $state['step'] = 'ask_client_type';
        mvSaveJson($sessionPath, $state);

        mvReply([
            'session_id' => $sessionId,
            'answer' => 'Подскажите, пожалуйста, Вы обращаетесь как физическое или юридическое лицо?',
            'time' => date('H:i:s')
        ]);
    }

    if (mvIsNegative($userText)) {
        $state['step'] = 'main_question';
        mvSaveJson($sessionPath, $state);

        mvReply([
            'session_id' => $sessionId,
            'answer' => 'Хорошо. Могу продолжить консультацию здесь. Что ещё Вас интересует?',
            'time' => date('H:i:s')
        ]);
    }
}

if ($state['step'] === 'ask_client_type') {
    $type = mvDetectClientType($userText);

    if ($type === '') {
        mvReply([
            'session_id' => $sessionId,
            'answer' => 'Уточните, пожалуйста: физическое или юридическое лицо?',
            'time' => date('H:i:s')
        ]);
    }

    if ($type === 'legal') {
        $state['step'] = 'ask_company';
        mvSaveJson($sessionPath, $state);

        mvReply([
            'session_id' => $sessionId,
            'answer' => 'Укажите, пожалуйста, наименование компании.',
            'time' => date('H:i:s')
        ]);
    }

    $state['step'] = 'ask_contact_name';
    mvSaveJson($sessionPath, $state);

    mvReply([
        'session_id' => $sessionId,
        'answer' => 'Укажите, пожалуйста, имя и фамилию.',
        'time' => date('H:i:s')
    ]);
}

if ($state['step'] === 'ask_company') {
    $state['company'] = trim($userText);
    $state['step'] = 'ask_city';
    mvSaveJson($sessionPath, $state);

    mvReply([
        'session_id' => $sessionId,
        'answer' => 'Укажите город или регион компании.',
        'time' => date('H:i:s')
    ]);
}

if ($state['step'] === 'ask_city') {
    $state['city'] = trim($userText);
    $state['step'] = 'ask_contact_name';
    mvSaveJson($sessionPath, $state);

    mvReply([
        'session_id' => $sessionId,
        'answer' => 'Укажите, пожалуйста, имя и фамилию контактного лица.',
        'time' => date('H:i:s')
    ]);
}

if ($state['step'] === 'ask_contact_name') {
    if (!mvValidName($userText)) {
        mvReply([
            'session_id' => $sessionId,
            'answer' => 'Укажите, пожалуйста, имя и фамилию, например: Сергей Валуев.',
            'time' => date('H:i:s')
        ]);
    }

    $state['contact_name'] = trim($userText);
    $state['step'] = 'ask_contact_position';
    mvSaveJson($sessionPath, $state);

    mvReply([
        'session_id' => $sessionId,
        'answer' => 'Укажите должность контактного лица.',
        'time' => date('H:i:s')
    ]);
}

if ($state['step'] === 'ask_contact_position') {
    $state['position'] = trim($userText);
    $state['step'] = 'ask_contact';
    mvSaveJson($sessionPath, $state);

    mvReply([
        'session_id' => $sessionId,
        'answer' => 'Укажите email или телефон для связи.',
        'time' => date('H:i:s')
    ]);
}

if ($state['step'] === 'ask_contact') {
    if (!mvValidContact($userText)) {
        mvReply([
            'session_id' => $sessionId,
            'answer' => 'Укажите корректный email или телефон.',
            'time' => date('H:i:s')
        ]);
    }

    $state['contact'] = trim($userText);
    $state['step'] = 'completed';

    mvSaveLead($leadCsv, $state);
    mvSaveJson($sessionPath, $state);

    mvReply([
        'session_id' => $sessionId,
        'answer' =>
            "Спасибо. Запрос сохранён в демонстрационном lead-файле.\n\n" .
            "В production-версии на этом шаге заявка может передаваться в почту, CRM или другой sales workflow.",
        'time' => date('H:i:s')
    ]);
}

$matchedFaq = mvMatchFaq($userText, $faq);

if ($matchedFaq) {
    $answer = (string)($matchedFaq['answer'] ?? '');

    if (!empty($matchedFaq['cta'])) {
        $answer .= "\n\n" . (string)$matchedFaq['cta'];
    }

    mvSaveJson($sessionPath, $state);

    mvReply([
        'session_id' => $sessionId,
        'answer' => $answer,
        'time' => date('H:i:s')
    ]);
}

mvSaveJson($sessionPath, $state);

mvReply([
    'session_id' => $sessionId,
    'answer' =>
        'Я могу помочь с подбором промышленного люка, размерами, количеством, RAL, документацией и подготовкой запроса на коммерческое предложение.',
    'time' => date('H:i:s')
]);

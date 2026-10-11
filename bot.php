<?php
declare(strict_types=1);
date_default_timezone_set(getenv('TZ') ?: 'Europe/Moscow');

const API_VERSION = '5.199';
$token = trim((string)getenv('VK_TOKEN'));
$groupId = (int)getenv('VK_GROUP_ID');
$dbPath = getenv('DB_PATH') ?: __DIR__ . '/data/raze.sqlite';
$questions = json_decode((string)file_get_contents(__DIR__ . '/questions.json'), true) ?: [];

if ($token === '' || $groupId <= 0) {
    fwrite(STDERR, "Set VK_TOKEN and VK_GROUP_ID environment variables.\n");
    exit(1);
}
if (!is_dir(dirname($dbPath))) mkdir(dirname($dbPath), 0775, true);
$db = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('CREATE TABLE IF NOT EXISTS users (user_id INTEGER PRIMARY KEY, q_index INTEGER NOT NULL DEFAULT 0, correct INTEGER NOT NULL DEFAULT 0, answered INTEGER NOT NULL DEFAULT 0, mode TEXT NOT NULL DEFAULT "learn", active INTEGER NOT NULL DEFAULT 0, wrong_json TEXT NOT NULL DEFAULT "[]", updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE IF NOT EXISTS results (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, correct INTEGER NOT NULL, answered INTEGER NOT NULL, percent INTEGER NOT NULL, finished_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
$adminIds = array_filter(array_map('intval', explode(',', (string)getenv('OWNER_IDS'))));

function vk(string $method, array $params = []): mixed {
    global $token;
    $params['access_token'] = $token;
    $params['v'] = API_VERSION;
    $url = 'https://api.vk.com/method/' . $method;
    $ctx = stream_context_create(['http' => ['method'=>'POST','header'=>"Content-Type: application/x-www-form-urlencoded\r\n",'content'=>http_build_query($params),'timeout'=>35]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) throw new RuntimeException("VK API request failed: $method");
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new RuntimeException("Invalid VK response");
    if (isset($data['error'])) throw new RuntimeException("VK API {$data['error']['error_code']}: {$data['error']['error_msg']}");
    return $data['response'] ?? [];
}
function keyboard(array $rows, bool $inline = false): string {
    return json_encode(['one_time'=>false,'inline'=>$inline,'buttons'=>$rows], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
function btn(string $label, string $payload, string $color='primary'): array {
    // VK limits button labels to 40 characters. Keep a little margin for Unicode labels.
    $chars = preg_split('//u', $label, -1, PREG_SPLIT_NO_EMPTY);
    if (is_array($chars) && count($chars) > 40) $label = implode('', array_slice($chars, 0, 37)) . '…';
    return ['action'=>['type'=>'text','label'=>$label,'payload'=>json_encode(['cmd'=>$payload], JSON_UNESCAPED_UNICODE)],'color'=>$color];
}
function sendMsg(int $peerId, string $message, ?string $keyboard = null): void {
    $params = ['peer_id'=>$peerId,'message'=>$message,'random_id'=>random_int(1, 2147483647)];
    if ($keyboard !== null) $params['keyboard'] = $keyboard;
    vk('messages.send', $params);
}
function mainKeyboard(): string {
    return keyboard([[btn('📚 Обучение','mode_learn','primary'),btn('📝 Экзамен','mode_exam','positive')],[btn('🔁 Повторить ошибки','mode_review','secondary')],[btn('📊 Мой результат','stats','secondary'),btn('ℹ️ Помощь','help','secondary')]]);
}
function getUser(PDO $db, int $uid): array {
    $s=$db->prepare('SELECT * FROM users WHERE user_id=?'); $s->execute([$uid]); $u=$s->fetch(PDO::FETCH_ASSOC);
    if (!$u) { $db->prepare('INSERT INTO users(user_id) VALUES(?)')->execute([$uid]); return getUser($db,$uid); }
    return $u;
}
function saveUser(PDO $db, int $uid, array $u): void {
    $s=$db->prepare('UPDATE users SET q_index=?,correct=?,answered=?,mode=?,active=?,wrong_json=?,updated_at=CURRENT_TIMESTAMP WHERE user_id=?');
    $s->execute([(int)$u['q_index'],(int)$u['correct'],(int)$u['answered'],(string)$u['mode'],(int)$u['active'],(string)$u['wrong_json'],$uid]);
}
function shuffledQuestion(array $q): array {
    // Keep answer indices stable; options are intentionally not shuffled in this starter version.
    return $q;
}
function showQuestion(PDO $db, int $peer, int $uid, array $u, array $questions, bool $review=false): void {
    $wrong = json_decode($u['wrong_json'] ?: '[]', true) ?: [];
    $pool = $review ? $wrong : range(0, max(0,count($questions)-1));
    if (!$pool || (int)$u['q_index'] >= count($pool) || !$questions) {
        $answered=(int)$u['answered']; $correct=(int)$u['correct']; $pct=$answered ? (int)round($correct*100/$answered) : 0;
        $db->prepare('INSERT INTO results(user_id,correct,answered,percent) VALUES(?,?,?,?)')->execute([$uid,$correct,$answered,$pct]);
        $u['active']=0; saveUser($db,$uid,$u);
        sendMsg($peer,"🏁 Тренировка завершена!\n\nПравильных: {$correct}\nОтветов: {$answered}\nРезультат: {$pct}%\n\nВыбери, что делать дальше.",mainKeyboard()); return;
    }
    $idx=(int)$pool[(int)$u['q_index']];
    $q=$questions[$idx];
    $rows=[];
    foreach ($q['options'] as $i=>$opt) {
        $rows[]=[btn(($i+1).'. '.$opt,'answer_'.$idx.'_'.$i,'primary')];
    }
    $rows[]=[btn('⏹ Завершить','finish','negative')];
    $modeLabel = $review ? 'Повторение ошибок' : (($u['mode']==='exam')?'Экзамен':'Обучение');
    $num=(int)$u['q_index']+1;
    sendMsg($peer,"🧠 {$modeLabel}\nВопрос {$num} из ".count($pool)."\nТема: {$q['category']}\n\n".$q['q'],keyboard($rows,true));
}
function startMode(PDO $db, int $peer, int $uid, array $u, string $mode, array $questions): void {
    $u['q_index']=0; $u['correct']=0; $u['answered']=0; $u['mode']=$mode; $u['active']=1;
    if ($mode !== 'review') $u['wrong_json']='[]';
    saveUser($db,$uid,$u);
    showQuestion($db,$peer,$uid,$u,$questions,$mode==='review');
}
function handle(PDO $db, int $peer, int $uid, string $text, ?array $payload, array $questions): void {
    $u=getUser($db,$uid);
    $cmd=(string)($payload['cmd'] ?? '');
    if ($cmd==='' && preg_match('/^(?:начать|старт|экзамен|помощь|help|\\/start|\\/help)$/iu',trim($text))) $cmd='help';
    if ($cmd==='' && preg_match('/^(?:меню|назад)$/iu',trim($text))) $cmd='menu';
    if ($cmd==='' && preg_match('/^(?:начать экзамен|экзамен)$/iu',trim($text))) $cmd='mode_exam';
    if ($cmd==='help') {
        sendMsg($peer,"Привет! Я бот для подготовки к посту администратора.\n\nНажимай кнопки ниже:\n• Обучение — вопросы с пояснениями\n• Экзамен — проверка знаний\n• Повторить ошибки — вопросы, на которые ты ответил неправильно\n• Мой результат — статистика\n\nОтветы выбираются кнопками. После каждого ответа я покажу правильный вариант и пояснение.",mainKeyboard()); return;
    }
    if ($cmd==='menu') { sendMsg($peer,'Главное меню:',mainKeyboard()); return; }
    if ($cmd==='mode_learn' || $cmd==='mode_exam') { startMode($db,$peer,$uid,$u,$cmd==='mode_exam'?'exam':'learn',$questions); return; }
    if ($cmd==='mode_review') {
        $wrong=json_decode($u['wrong_json'] ?: '[]',true) ?: [];
        if (!$wrong) { sendMsg($peer,'Пока нет ошибок для повторения. Пройди обучение или экзамен сначала.',mainKeyboard()); return; }
        startMode($db,$peer,$uid,$u,'review',$questions); return;
    }
    if ($cmd==='stats') {
        $s=$db->prepare('SELECT COUNT(*) sessions, COALESCE(MAX(percent),0) best, COALESCE(AVG(percent),0) avg FROM results WHERE user_id=?'); $s->execute([$uid]); $r=$s->fetch(PDO::FETCH_ASSOC);
        sendMsg($peer,"📊 Твоя статистика\nЗавершённых попыток: {$r['sessions']}\nЛучший результат: {$r['best']}%\nСредний результат: ".round((float)$r['avg'])."%",mainKeyboard()); return;
    }
    if ($cmd==='finish') { $u['active']=0; saveUser($db,$uid,$u); sendMsg($peer,'Тренировка остановлена. Можешь начать заново в меню.',mainKeyboard()); return; }
    if (preg_match('/^answer_(\d+)_(\d+)$/',$cmd,$m)) {
        if (!(int)$u['active']) { sendMsg($peer,'Сначала выбери режим в меню.',mainKeyboard()); return; }
        $qi=(int)$m[1]; $ai=(int)$m[2];
        if (!isset($questions[$qi])) { sendMsg($peer,'Не удалось найти вопрос. Начни заново.',mainKeyboard()); return; }
        $q=$questions[$qi];
        if ($ai<0 || $ai>=count($q['options'])) return;
        $right=((int)$q['correct']===$ai);
        $u['answered']=(int)$u['answered']+1;
        if ($right) { $u['correct']=(int)$u['correct']+1; $feedback="✅ Правильно!"; }
        else {
            $feedback="❌ Неправильно.\nПравильный ответ: ".$q['options'][(int)$q['correct']];
            $wrong=json_decode($u['wrong_json'] ?: '[]',true) ?: [];
            if (!in_array($qi,$wrong,true)) $wrong[]=$qi;
            $u['wrong_json']=json_encode($wrong);
        }
        saveUser($db,$uid,$u);
        $u['q_index']=(int)$u['q_index']+1; saveUser($db,$uid,$u);
        sendMsg($peer,$feedback."\n\n📖 Пояснение: ".$q['explanation'],keyboard([[btn('➡️ Следующий вопрос','next','positive')],[btn('⏹ Завершить','finish','negative')]],true));
        return;
    }
    if ($cmd==='next') { showQuestion($db,$peer,$uid,$u,$questions,$u['mode']==='review'); return; }
    // Allow users to begin with common plain-text phrases.
    if (preg_match('/^(?:начать|старт|начать экзамен)$/iu',trim($text))) { sendMsg($peer,'Выбери режим подготовки:',mainKeyboard()); return; }
    sendMsg($peer,'Используй кнопки меню, чтобы выбрать действие.',mainKeyboard());
}

echo "RAZE Admin Exam Bot started. Questions: ".count($questions).PHP_EOL;
try {
    $lp = vk('groups.getLongPollServer',['group_id'=>$groupId]);
} catch (Throwable $e) {
    fwrite(STDERR, "Не удалось получить Long Poll сервер VK: ".$e->getMessage()."\n");
    fwrite(STDERR, "Включи Long Poll API в настройках сообщества VK и активируй событие message_new.\n");
    exit(1);
}
if (!is_array($lp) || empty($lp['server']) || empty($lp['key']) || !isset($lp['ts'])) {
    fwrite(STDERR, "VK вернул некорректные параметры Long Poll. Проверь VK_GROUP_ID и настройки Long Poll сообщества.\n");
    exit(1);
}
$server=$lp['server']; $key=$lp['key']; $ts=(string)$lp['ts'];
while (true) {
    $url=$server.'?'.http_build_query(['act'=>'wait','key'=>$key,'ts'=>$ts,'wait'=>25,'mode'=>2]);
    $ctx=stream_context_create(['http'=>['timeout'=>35]]);
    $raw=@file_get_contents($url,false,$ctx);
    if ($raw===false) { sleep(2); continue; }
    $data=json_decode($raw,true);
    if (!is_array($data)) { sleep(1); continue; }
    if (isset($data['failed'])) {
        if ((int)$data['failed']===1) $ts=(string)($data['ts'] ?? $ts);
        else { $lp=vk('groups.getLongPollServer',['group_id'=>$groupId]); if (!is_array($lp) || empty($lp['server']) || empty($lp['key']) || !isset($lp['ts'])) { error_log('VK returned invalid Long Poll settings after failed event.'); sleep(2); continue; } $server=$lp['server'];$key=$lp['key'];$ts=(string)$lp['ts']; }
        continue;
    }
    $ts=(string)($data['ts'] ?? $ts);
    foreach (($data['updates'] ?? []) as $update) {
        if (($update['type'] ?? '')!=='message_new') continue;
        $msg=$update['object']['message'] ?? $update['object'] ?? [];
        $from=(int)($msg['from_id'] ?? 0); $peer=(int)($msg['peer_id'] ?? $from);
        if ($from<=0 || $peer<=0) continue;
        $text=(string)($msg['text'] ?? '');
        $payload=null;
        if (isset($msg['payload'])) {
            $payload=is_array($msg['payload'])?$msg['payload']:json_decode((string)$msg['payload'],true);
        }
        try { handle($db,$peer,$from,$text,is_array($payload)?$payload:null,$questions); }
        catch (Throwable $e) { error_log('Handler error: '.$e->getMessage()); try { sendMsg($peer,'Произошла ошибка обработки. Попробуй ещё раз или напиши «меню».',mainKeyboard()); } catch(Throwable $ignored) {} }
    }
}

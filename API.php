<?php

// ================= CONFIG =================
$botToken = "8553193148:AAEuIuo6aVKE92Pcc3gVzXlM33F-oQ_UcU4";
$admin_id =" 7965320174"
$apiURL = "https://api.telegram.org/bot$botToken/";

$MAX_DAILY = 10; // الحد اليومي لكل مستخدم

// ================= STORAGE =================
// ملف قاعدة البيانات بصيغة JSON
$db_file = "database.json";

if (!file_exists($db_file)) {
    file_put_contents($db_file, json_encode([]));
}

function load_db() {
    global $db_file;
    return json_decode(file_get_contents($db_file), true);
}

function save_db($db) {
    global $db_file;
    file_put_contents($db_file, json_encode($db, JSON_PRETTY_PRINT));
}

// تم تحويل الملف من py الى php بواسطة الخال ALOUSH @@TT1TT6
// ================= HELPERS =================
function sendMessage($chat_id, $text, $reply_markup = null) {
    global $apiURL;
    $data = [
        "chat_id" => $chat_id,
        "text" => $text,
        "parse_mode" => "Markdown"
    ];
    if ($reply_markup) $data["reply_markup"] = json_encode($reply_markup);
    file_get_contents($apiURL."sendMessage?".http_build_query($data));
}

function sendPhoto($chat_id, $photo) {
    global $apiURL;
    file_get_contents($apiURL."sendPhoto?chat_id=$chat_id&photo=".urlencode($photo));
}

function is_banned($user_id) {
    $db = load_db();
    return isset($db[$user_id]["banned"]) && $db[$user_id]["banned"] == true;
}

function check_limit($user_id) {
    global $MAX_DAILY;
    $db = load_db();

    if (!isset($db[$user_id]["count"])) $db[$user_id]["count"] = 0;

    if ($db[$user_id]["count"] >= $MAX_DAILY) return false;

    return true;
}

function increase_count($user_id) {
    $db = load_db();
    if (!isset($db[$user_id]["count"])) $db[$user_id]["count"] = 0;
    $db[$user_id]["count"]++;
    save_db($db);
}

function reset_daily() {
    $db = load_db();
    foreach ($db as $uid => $info) {
        $db[$uid]["count"] = 0;
    }
    save_db($db);
}

// ================= ANALYTICS =================
function add_user($id) {
    $db = load_db();
    if (!isset($db[$id])) {
        $db[$id] = [
            "count" => 0,
            "session" => [],
            "banned" => false
        ];
        save_db($db);
    }
}

function save_session($uid, $key, $value) {
    $db = load_db();
    if (!isset($db[$uid]["session"])) $db[$uid]["session"] = [];
    $db[$uid]["session"][$key] = $value;
    save_db($db);
}

function get_session($uid, $key) {
    $db = load_db();
    return $db[$uid]["session"][$key] ?? null;
}

// ================= GOOGLE TOKEN =================
function get_token() {
    $headers = [
        "Content-Type: application/json",
        "X-Android-Package: com.photoroom.app",
        "X-Android-Cert: 0424A4898A4B33940D8BF16E44251B876E97F8D0",
        "Accept-Language: en-US",
        "User-Agent: Dalvik/2.1.0",
    ];

    $params = "?key=AIzaSyAJGrgbFGB_-h8V2oJLr4b-_ipetqM0duU";

    $body = json_encode(["clientType" => "CLIENT_TYPE_ANDROID"]);

    $ch = curl_init("https://www.googleapis.com/identitytoolkit/v3/relyingparty/signupNewUser".$params);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true)["idToken"];
}

// ================= AI GENERATION =================
function generate_images($prompt, $styleId, $sizeId) {

    $token = get_token();

    $headers = [
        "Accept: text/event-stream",
        "Authorization: $token",
        "Content-Type: application/json",
        "User-Agent: okhttp/4.12.0",
        "Pr-App-Version: 2025.47.03",
        "Pr-Platform: android"
    ];

    $payload = json_encode([
        "userPrompt" => $prompt,
        "appId" => "expert",
        "styleId" => $styleId,
        "sizeId" => $sizeId,
        "numberOfImages" => 4
    ]);

    $ch = curl_init("https://serverless-api.photoroom.com/v2/ai-tools/generate-images");
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $raw = curl_exec($ch);
    curl_close($ch);

    $bg = [];
    $nobg = [];

    $lines = explode("\n", $raw);

    foreach ($lines as $line) {
        if (strpos($line, '"aiImageResult"') !== false) {
            preg_match('/"imageUrl":"(.*?)"/', $line, $m);
            if (!empty($m[1])) $bg[] = $m[1];
        }
        if (strpos($line, '"aiImageWithoutBackgroundResult"') !== false) {
            preg_match('/"imageUrl":"(.*?)"/', $line, $m);
            if (!empty($m[1])) $nobg[] = $m[1];
        }
    }

    return [$bg, $nobg];
}
// ================= TELEGRAM UPDATE HANDLER =================

$update = json_decode(file_get_contents("php://input"), true);

if (!$update) exit;

$chat_id =
    $update["message"]["chat"]["id"]
    ?? $update["callback_query"]["message"]["chat"]["id"]
    ?? null;

$user_id =
    $update["message"]["from"]["id"]
    ?? $update["callback_query"]["from"]["id"]
    ?? null;

$text = $update["message"]["text"] ?? "";
$callback = $update["callback_query"]["data"] ?? null;

add_user($user_id); // تأكد أن المستخدم مسجل

// منع المحظورين
if (is_banned($user_id)) {
    sendMessage($chat_id, "🚫 *تم حظرك من استخدام هذا البوت*\nتواصل مع الإدارة لرفع الحظر.");
    exit;
}


// ================= ADMIN PANEL =================
if ($text == "/admin" && $user_id == $admin_id) {

    $db = load_db();
    $users_count = count($db);
    $total_ops = 0;

    foreach ($db as $u) {
        if (isset($u["count"])) $total_ops += $u["count"];
    }

    $msg =
        "🔷 *لوحة تحكم الأدمن*\n\n".
        "👥 عدد المستخدمين: *$users_count*\n".
        "⚙️ عدد عمليات التوليد: *$total_ops*\n\n".
        "اختر إجراء:";

    $buttons = [
        [["text" => "🚫 حظر مستخدم", "callback_data" => "admin:ban"]],
        [["text" => "♻️ رفع الحظر", "callback_data" => "admin:unban"]],
        [["text" => "📢 ارسال اذاعه", "callback_data" => "admin:broadcast"]],
        [["text" => "🔄 تصفير المحاولات للجميع", "callback_data" => "admin:reset"]],
    ];

    sendMessage($chat_id, $msg, ["inline_keyboard" => $buttons]);
    exit;
}

// تم تحويل الملف من py الى php بواسطة الخال ALOUSH @@TT1TT6
// ================= ADMIN CALLBACKS =================
if ($callback && $user_id == $admin_id) {

    // حظر مستخدم
    if ($callback == "admin:ban") {
        sendMessage($chat_id, "أرسل ايدي المستخدم الذي تريد حظره:");
        save_session($user_id, "admin_wait", "ban");
        exit;
    }

    // رفع الحظر
    if ($callback == "admin:unban") {
        sendMessage($chat_id, "أرسل ايدي المستخدم لرفع الحظر:");
        save_session($user_id, "admin_wait", "unban");
        exit;
    }

    // إرسال اذاعه
    if ($callback == "admin:broadcast") {
        sendMessage($chat_id, "أرسل الآن الرسالة التي تريد ارسالها للجميع:");
        save_session($user_id, "admin_wait", "broadcast");
        exit;
    }

    // تصفير المحاولات//@TT1TT6
    if ($callback == "admin:reset") {
        reset_daily();
        sendMessage($chat_id, "✔️ تم تصفير المحاولات اليوميه لكل المستخدمين.");
        exit;
    }
}

// ================= ADMIN WAIT LOGIC =================
if ($user_id == $admin_id && get_session($user_id, "admin_wait")) {

    $mode = get_session($user_id, "admin_wait");

    // حظر
    if ($mode == "ban") {
        $id = intval($text);
        $db = load_db();
        if (!isset($db[$id])) {
            sendMessage($chat_id, "❌ المستخدم غير موجود.");
        } else {
            $db[$id]["banned"] = true;
            save_db($db);
            sendMessage($chat_id, "✔️ تم حظر المستخدم.");
        }
    }

    // رفع الحظر
    if ($mode == "unban") {
        $id = intval($text);
        $db = load_db();
        if (!isset($db[$id])) {
            sendMessage($chat_id, "❌ المستخدم غير موجود.");
        } else {
            $db[$id]["banned"] = false;
            save_db($db);
            sendMessage($chat_id, "✔️ تم رفع الحظر.");
        }
    }

    // اذاعه
    if ($mode == "broadcast") {
        $msg = $text;
        $db = load_db();

        $count = 0;
        foreach ($db as $uid => $info) {
            sendMessage($uid, $msg);
            $count++;
        }

        sendMessage($chat_id, "✔️ تم إرسال الإذاعه إلى *$count* مستخدم.");
    }

    // إزالة وضع الانتظار
    save_session($user_id, "admin_wait", null);
    exit;
}

// تم تحويل الملف من py الى php بواسطة الخال ALOUSH @@TT1TT6


// ================= USER INTERFACE =================

// ستايلات
$STYLES = [
    "diversity" => "التنوع — Diversity",
    "hyper-realistic" => "واقعي هواية — Hyper Realistic",
    "impressionist" => "ستايل انطباعي — Impressionist",
    "low-poly" => "ستايل خفيف التفاصيل — Low Poly",
    "isometric" => "منظور أيزومتريك — Isometric",
    "cyberpunk" => "سايبربنك — Cyberpunk",
    "baroque" => "باروكي — Baroque",
    "abstract-expressionism" => "مجرد تعبيري — Abstract Expressionism",
    "photorealistic-cgi" => "CGI واقعي — Photorealistic CGI",
    "surrealist" => "سيريالي — Surrealist",
]; 

// اختيار الحجم 
$SIZES = [
    "SQUARE_HD" => "مربع 1:1",
    "PORTRAIT_4_3" => "طولي 3:4",
    "PORTRAIT_16_9" => "طولي 9:16",
    "LANDSCAPE_4_3" => "عرضي 4:3",
    "LANDSCAPE_16_9" => "عرضي 16:9"
];


// ======================= START =======================
if ($text == "/start") {

    $buttons = [];
    foreach ($STYLES as $id => $name) {
        $buttons[] = [["text" => $name, "callback_data" => "style:$id"]];
    }

    sendMessage(
        $chat_id,
        "مرحباً بك عزيزي المستخدم 🌟\n".
        "اختر ستايل الصورة حتى نبدأ:",
        ["inline_keyboard" => $buttons]
    );
    exit;
}


// تم تحويل الملف من py الى php بواسطة الخال ALOUSH @@TT1TT6

// ======================= CALLBACKS =======================
if ($callback) {

    // اختيار ستايل
    if (strpos($callback, "style:") === 0) {

        $styleId = explode(":", $callback)[1];
        save_session($user_id, "styleId", $styleId);

        $buttons = [];
        foreach ($SIZES as $id => $name) {
            $buttons[] = [["text" => $name, "callback_data" => "size:$id"]];
        }

        sendMessage($chat_id, "اختار حجم الصورة:", ["inline_keyboard" => $buttons]);
        exit;
    }

    // اختيار الحجم
    if (strpos($callback, "size:") === 0) {
        $sizeId = explode(":", $callback)[1];

        save_session($user_id, "sizeId", $sizeId);
        save_session($user_id, "await", "prompt");

        sendMessage($chat_id, "ارسل الوصف الذي تريد أن اسوي صوره عليه 🔥:");
        exit;
    }

    // إعادة توليد
    if ($callback == "regen") {

        if (!check_limit($user_id)) {
            sendMessage($chat_id, "🚫 وصلت للحد اليومي! حاول بكرة ❤️");
            exit;
        }

        $prompt = get_session($user_id, "prompt");
        $styleId = get_session($user_id, "styleId");
        $sizeId = get_session($user_id, "sizeId");

        sendMessage($chat_id, "⏳ جاري إعادة التوليد…");

        list($bg, $nb) = generate_images($prompt, $styleId, $sizeId);

        increase_count($user_id);

        foreach ($bg as $img) sendPhoto($chat_id, $img);
        foreach ($nb as $img) sendPhoto($chat_id, $img);

        sendMessage($chat_id,
            "✨ الصور جاهزة\nاختر إجراء:",
            ["inline_keyboard" => [
                [["text" => "🔁 إعادة توليد", "callback_data" => "regen"]],
                [["text" => "🏠 رجوع", "callback_data" => "home"]],
            ]]
        );

        exit;
    }

    // رجوع
    if ($callback == "home") {
        sendMessage($chat_id, "انقر فوق /start للرجوع للقائمه الرئيسيه");
        exit;
    }
}



// ======================= PROMPT INPUT =======================
if (get_session($user_id, "await") == "prompt") {

    $prompt = $text;

    save_session($user_id, "prompt", $prompt);
    save_session($user_id, "await", null);

    if (!check_limit($user_id)) {
        sendMessage($chat_id, "🚫 وصلت للحد المسموح اليوم!");
        exit;
    }

    $styleId = get_session($user_id, "styleId");
    $sizeId = get_session($user_id, "sizeId");

    sendMessage($chat_id, "⏳ جاري توليد الصور… 🔥");

sendMessage($chat_id, "⏳");

    list($bg, $nb) = generate_images($prompt, $styleId, $sizeId);

    increase_count($user_id);

    foreach ($bg as $img) sendPhoto($chat_id, $img);
    foreach ($nb as $img) sendPhoto($chat_id, $img);

    sendMessage($chat_id,
        "✔️ خلصو الصور\nاختر إجراء:",
        ["inline_keyboard" => [
            [["text" => "🔁 إعادة توليد", "callback_data" => "regen"]],
            [["text" => "🏠 رجوع", "callback_data" => "home"]],
        ]]
    );

    exit;
}

?>
// تم تحويل الملف من py الى php بواسطة الخال ALOUSH @@TT1TT6
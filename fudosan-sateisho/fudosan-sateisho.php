<?php
/**
 * Plugin Name: 不動産 査定書作成受付
 * Description: 査定書の作成を受け付けるフォーム。物件情報とメールを受け取り、受付完了メールを自動返信＋管理者に通知。査定書は後日スタッフが作成して送付。ショートコード [fudosan_sateisho] をページに貼るだけ。
 * Version: 1.1.0
 * Author: (運営者)
 * License: GPLv2 or later
 * Text Domain: fudosan-sateisho
 *
 * ★法的注意: スタッフが作成する査定書は宅建業の「価格査定（参考価格）」であり、
 *   不動産鑑定士の「鑑定評価」ではない。免責文で明示すること。公開前に弁護士等の確認を推奨。
 */

if (!defined('ABSPATH')) exit; // 直接アクセス禁止

define('FSS_VER', '1.1.0');
define('FSS_OPT', 'fudosan_sateisho_options');
define('FSS_ENDPOINT', 'https://www.reinfolib.mlit.go.jp/ex-api/external/XIT001');

/**
 * 自動更新の置き場（update.json の URL）。
 * ミカタのサーバー/WPサイト上のフォルダに update.json と fudosan-sateisho.zip を
 * 置き、その update.json の URL をここに設定する。新バージョンを置くと WP管理画面に
 * 「更新可能」バッジが出て、ワンクリック更新できる（各サイトへの手動配布は不要）。
 * ※ 空なら自動更新は無効（手動アップロードでの運用は可能）。
 */
define('FSS_UPDATE_URL', 'https://raw.githubusercontent.com/yoshimucom-gif/fudosan-sateisho-plugin/main/update.json');

/* 自動更新チェッカー（管理画面のみ・URL未設定なら無効） */
if (is_admin()) {
    require_once __DIR__ . '/includes/plugin-updater.php';
    new FSS_Sateisho_Updater(__FILE__, FSS_UPDATE_URL);
}

/* =========================================================================
 * 1. 有効化: リード保存テーブル作成
 * ======================================================================= */
register_activation_hook(__FILE__, 'fss_activate');
function fss_activate() {
    global $wpdb;
    $table = $wpdb->prefix . 'fudosan_sateisho_leads';
    $charset = $wpdb->get_charset_collate();
    // dbDeltaは「1カラム1行」でないと既存テーブルへのカラム追加を取りこぼす
    $sql = "CREATE TABLE $table (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL,
        email VARCHAR(191) NOT NULL,
        ptype VARCHAR(20) NULL,
        address VARCHAR(255) NULL,
        details LONGTEXT NULL,
        pref VARCHAR(50) NULL,
        city VARCHAR(50) NULL,
        area FLOAT NULL,
        build_year INT NULL,
        station_min INT NULL,
        station_name VARCHAR(100) NULL,
        floor_plan VARCHAR(30) NULL,
        district VARCHAR(100) NULL,
        purpose VARCHAR(50) NULL,
        low BIGINT NULL,
        mid BIGINT NULL,
        high BIGINT NULL,
        sample_size INT NULL,
        marketing_opt_in TINYINT(1) DEFAULT 0,
        PRIMARY KEY  (id)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    fss_ensure_columns();
}

/* dbDeltaの取りこぼし対策: 不足カラムを明示的にALTERで追加（確実） */
function fss_ensure_columns() {
    global $wpdb;
    $t = $wpdb->prefix . 'fudosan_sateisho_leads';
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t)) !== $t) return;
    $cols = $wpdb->get_col("SHOW COLUMNS FROM `$t`", 0);
    if (!is_array($cols)) return;
    $need = array(
        'address'      => 'VARCHAR(255) NULL',
        'details'      => 'LONGTEXT NULL',
        'purpose'      => 'VARCHAR(50) NULL',
    );
    foreach ($need as $c => $def) {
        if (!in_array($c, $cols, true)) {
            $wpdb->query("ALTER TABLE `$t` ADD COLUMN `$c` $def");
        }
    }
}

/* 自動更新でバージョンが上がったらテーブル定義を追従（新カラム追加等） */
add_action('plugins_loaded', 'fss_maybe_upgrade');
function fss_maybe_upgrade() {
    if (get_option('fss_db_ver') !== FSS_VER) {
        fss_activate();
        update_option('fss_db_ver', FSS_VER);
    }
}

/* =========================================================================
 * 2. 設定（APIキー・運営者情報）
 * ======================================================================= */
function fss_opt($key, $default = '') {
    $o = get_option(FSS_OPT, array());
    return isset($o[$key]) && $o[$key] !== '' ? $o[$key] : $default;
}

add_action('admin_menu', function () {
    // 専用のトップレベルメニュー（設定 と 受付一覧 をまとめる）
    add_menu_page('査定書作成受付', '査定書作成受付', 'manage_options', 'fudosan-sateisho', 'fss_settings_page', 'dashicons-media-document', 59);
    add_submenu_page('fudosan-sateisho', '設定', '設定', 'manage_options', 'fudosan-sateisho', 'fss_settings_page');
    add_submenu_page('fudosan-sateisho', '受付一覧', '受付一覧', 'manage_options', 'fudosan-sateisho-leads', 'fss_leads_page');
});

add_action('admin_init', function () {
    register_setting('fss_group', FSS_OPT, 'fss_sanitize_options');
});

function fss_sanitize_options($in) {
    $areas = array();
    if (!empty($in['areas']) && is_array($in['areas'])) {
        foreach ($in['areas'] as $a) { $a = sanitize_text_field($a); if ($a !== '') $areas[] = $a; }
    }
    return array(
        'site_name'        => sanitize_text_field($in['site_name'] ?? '査定書作成受付'),
        'operator_name'    => sanitize_text_field($in['operator_name'] ?? ''),
        'operator_contact' => sanitize_text_field($in['operator_contact'] ?? ''),
        'from_email'       => sanitize_email($in['from_email'] ?? get_option('admin_email')),
        'notify_email'     => sanitize_email($in['notify_email'] ?? ''),
        'privacy_url'      => esc_url_raw($in['privacy_url'] ?? ''),
        'terms_url'        => esc_url_raw($in['terms_url'] ?? ''),
        // 表示項目（未送信=チェック外れ=非表示）
        'show_district'    => !empty($in['show_district']) ? '1' : '',
        'show_station'     => !empty($in['show_station']) ? '1' : '',
        'show_floor_plan'  => !empty($in['show_floor_plan']) ? '1' : '',
        'show_build_year'  => !empty($in['show_build_year']) ? '1' : '',
        'show_purpose'     => !empty($in['show_purpose']) ? '1' : '',
        'show_marketing'   => !empty($in['show_marketing']) ? '1' : '',
        // 対象エリア（空=全国）
        'areas'            => $areas,
        // 自動返信メール
        'mail_subject'     => sanitize_text_field($in['mail_subject'] ?? ''),
        'mail_body'        => sanitize_textarea_field($in['mail_body'] ?? ''),
        // 装飾（色）
        'color_brand'      => sanitize_hex_color($in['color_brand'] ?? '')    ?: '#1f6feb',
        'color_btn_text'   => sanitize_hex_color($in['color_btn_text'] ?? '') ?: '#ffffff',
        'color_title'      => sanitize_hex_color($in['color_title'] ?? '')    ?: '#1f6feb',
        'color_badge'      => sanitize_hex_color($in['color_badge'] ?? '')    ?: '#ff5a36',
    );
}

/* 利用目的の選択肢（リードの質を測る重要データ） */
function fss_purposes() {
    return array(
        '売却を検討している',
        '相続した・相続する予定',
        '離婚による財産分与',
        '住み替えを検討している',
        '資産価値を把握したい',
        'その他',
    );
}

/* セレクトの選択肢 */
function fss_opt_list($key) {
    switch ($key) {
        case 'direction':    return array('北','北東','東','南東','南','南西','西','北西');
        case 'land_right':   return array('所有権','借地権');
        case 'structure':    return array('木造','軽量鉄骨造','重量鉄骨造','鉄筋コンクリート造(RC)','鉄骨鉄筋コンクリート造(SRC)','その他');
        case 'road_contact': return array('一方','角地','二方','三方','四方');
        case 'road_type':    return array('公道','私道');
        case 'layout':       return array('1R','1K','1DK','1LDK','2K','2DK','2LDK','3K','3DK','3LDK','4LDK以上');
    }
    return array();
}

/* 物件種別ごとの入力項目（マンション/戸建て/土地で異なる）。
   type: text|number|select|textarea|check、req: 必須、opts: fss_opt_list のキー */
function fss_property_fields() {
    return array(
        'mansion' => array(
            array('key'=>'mansion_name',  'label'=>'マンション名', 'type'=>'text',   'req'=>true,  'ph'=>'例：〇〇マンション'),
            array('key'=>'floor',         'label'=>'階数',        'type'=>'number', 'req'=>false, 'ph'=>'例：5'),
            array('key'=>'room_no',       'label'=>'部屋番号',    'type'=>'text',   'req'=>false, 'ph'=>'例：503'),
            array('key'=>'exclusive_area','label'=>'専有面積（㎡）','type'=>'number','req'=>true,  'ph'=>'例：70'),
            array('key'=>'direction',     'label'=>'方角',        'type'=>'select', 'req'=>false, 'opts'=>'direction'),
            array('key'=>'corner',        'label'=>'角部屋',      'type'=>'check',  'req'=>false, 'chk'=>'角部屋である'),
            array('key'=>'build_year',    'label'=>'築年（西暦）', 'type'=>'number', 'req'=>true,  'ph'=>'例：2015'),
            array('key'=>'layout',        'label'=>'間取り',      'type'=>'select', 'req'=>false, 'opts'=>'layout'),
            array('key'=>'reform',        'label'=>'リフォーム履歴','type'=>'textarea','req'=>false,'ph'=>'例：2020年に水回りをリフォーム'),
        ),
        'house' => array(
            array('key'=>'floors',        'label'=>'階建',        'type'=>'number', 'req'=>false, 'ph'=>'例：2'),
            array('key'=>'build_year',    'label'=>'築年（西暦）', 'type'=>'number', 'req'=>true,  'ph'=>'例：2010'),
            array('key'=>'land_right',    'label'=>'土地権利',    'type'=>'select', 'req'=>true,  'opts'=>'land_right'),
            array('key'=>'land_area',     'label'=>'土地面積（㎡）','type'=>'number','req'=>true,  'ph'=>'例：120'),
            array('key'=>'building_area', 'label'=>'建物面積（㎡）','type'=>'number','req'=>true,  'ph'=>'例：95'),
            array('key'=>'structure',     'label'=>'建築構造',    'type'=>'select', 'req'=>true,  'opts'=>'structure'),
            array('key'=>'road_contact',  'label'=>'接道状況',    'type'=>'select', 'req'=>false, 'opts'=>'road_contact'),
            array('key'=>'road_width',    'label'=>'前面道路幅員（m）','type'=>'number','req'=>false,'ph'=>'例：4'),
            array('key'=>'road_direction','label'=>'接道方向',    'type'=>'select', 'req'=>false, 'opts'=>'direction'),
            array('key'=>'road_type',     'label'=>'道路種別',    'type'=>'select', 'req'=>false, 'opts'=>'road_type'),
            array('key'=>'road_frontage', 'label'=>'接道間口（m）','type'=>'number','req'=>false, 'ph'=>'例：5'),
            array('key'=>'layout',        'label'=>'間取り',      'type'=>'select', 'req'=>false, 'opts'=>'layout'),
            array('key'=>'reform',        'label'=>'リフォーム履歴','type'=>'textarea','req'=>false,'ph'=>'例：2020年に外壁塗装'),
        ),
        'land' => array(
            array('key'=>'land_area',     'label'=>'土地面積（㎡）','type'=>'number','req'=>true,  'ph'=>'例：150'),
            array('key'=>'land_right',    'label'=>'土地権利',    'type'=>'select', 'req'=>true,  'opts'=>'land_right'),
            array('key'=>'road_contact',  'label'=>'接道状況',    'type'=>'select', 'req'=>true,  'opts'=>'road_contact'),
            array('key'=>'road_width',    'label'=>'前面道路幅員（m）','type'=>'number','req'=>false,'ph'=>'例：4'),
            array('key'=>'road_direction','label'=>'接道方向',    'type'=>'select', 'req'=>false, 'opts'=>'direction'),
            array('key'=>'road_type',     'label'=>'道路種別',    'type'=>'select', 'req'=>false, 'opts'=>'road_type'),
            array('key'=>'road_frontage', 'label'=>'接道間口（m）','type'=>'number','req'=>false, 'ph'=>'例：8'),
        ),
    );
}

/* teaser で選べる項目（fields属性で指定）。req=必須扱い */
function fss_teaser_fields() {
    return array(
        'purpose'    => array('label' => 'ご利用目的',   'name' => 'purpose',    'req' => false),
        'ptype'      => array('label' => '物件種別',     'name' => 'ptype',      'req' => true),
        'pref'       => array('label' => '都道府県',     'name' => 'pref_code',  'req' => true),
        'city'       => array('label' => '市区町村',     'name' => 'city_code',  'req' => true),
        'area'       => array('label' => '面積（㎡）',   'name' => 'area',       'req' => true),
        'build_year' => array('label' => '築年（西暦）', 'name' => 'build_year', 'req' => false),
    );
}

/* fields="ptype,pref,city" を検証済みの順序付きリストに */
function fss_parse_teaser_fields($raw) {
    $known = fss_teaser_fields();
    $out = array();
    foreach (explode(',', (string)$raw) as $k) {
        $k = trim($k);
        if ($k !== '' && isset($known[$k]) && !in_array($k, $out, true)) $out[] = $k;
    }
    if (!$out) $out = array('ptype', 'pref', 'city');
    // 市区町村は都道府県が無いと選べないので、無ければ直前に補う
    $ci = array_search('city', $out, true);
    if ($ci !== false && !in_array('pref', $out, true)) array_splice($out, $ci, 0, array('pref'));
    return $out;
}

/* #rrggbb → "r,g,b"（ブランド色を rgba() で薄く使うため） */
function fss_hex_to_rgb($hex) {
    $hex = ltrim((string)$hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) return '31,111,235';
    return hexdec(substr($hex, 0, 2)) . ',' . hexdec(substr($hex, 2, 2)) . ',' . hexdec(substr($hex, 4, 2));
}

/* 表示項目の判定（未保存＝デフォルト表示、保存済みは値そのもの。空='非表示'を区別） */
function fss_show($key) {
    $o = get_option(FSS_OPT, array());
    if (!is_array($o) || !array_key_exists('show_' . $key, $o)) return true; // 未設定=表示
    return $o['show_' . $key] === '1';
}

/* 対象エリアで都道府県を絞る（未設定なら全国）。数値文字列キーは整数化されるため非strict比較 */
function fss_area_prefs() {
    $all = fss_prefs();
    $sel = fss_opt('areas', array());
    if (empty($sel) || !is_array($sel)) return $all;
    $sel = array_map('strval', $sel);
    $out = array();
    foreach ($all as $code => $name) if (in_array((string)$code, $sel, true)) $out[(string)$code] = $name;
    return $out ?: $all;
}

/* 受付完了メールの初期本文（お客様へ・差し込みタグ付き） */
function fss_default_mail_body() {
    return "【{site_name}】査定書作成のお申し込みを受け付けました\n\n"
        . "この度はお申し込みいただきありがとうございます。\n"
        . "以下の内容で査定書の作成を受け付けました。\n\n"
        . "{property_details}\n\n"
        . "担当者が査定書を作成し、後日このメールアドレス宛にお送りします。\n"
        . "いましばらくお待ちください。\n\n"
        . "───────────────────────────────\n"
        . "【ご注意】\n"
        . "・作成する査定書は宅建業者による『価格査定（参考価格）』であり、\n"
        . "  不動産鑑定士による『鑑定評価』ではありません。\n"
        . "・実際の売却価格・成約価格を保証するものではありません。\n"
        . "───────────────────────────────\n\n"
        . "{operator_name}\n"
        . "お問い合わせ: {operator_contact}";
}

/* 管理者通知メールの本文（スタッフへ） */
function fss_admin_notify_body($ctx, $email) {
    $pd = array();
    $pd[] = "■ 物件種別 : {$ctx['ptype_label']}";
    if (!empty($ctx['address'])) $pd[] = "■ 物件住所 : {$ctx['address']}";
    if (!empty($ctx['purpose'])) $pd[] = "■ 利用目的 : {$ctx['purpose']}";
    if (!empty($ctx['details']))  $pd[] = $ctx['details'];
    return "査定書作成の受付が届きました。\n\n"
        . "■ お客様メール : {$email}\n"
        . implode("\n", $pd) . "\n"
        . "\n管理画面「査定書作成受付 → 受付一覧」からも確認できます。";
}

function fss_settings_page() {
    $o = get_option(FSS_OPT, array());
    $sel_areas = (isset($o['areas']) && is_array($o['areas'])) ? $o['areas'] : array();
    ?>
    <div class="wrap">
        <h1>査定書作成受付 設定</h1>
        <?php if (isset($_GET['testmail'])) {
            $tm_ok = ($_GET['testmail'] === '1');
            $tm_to = isset($_GET['to']) ? sanitize_email(wp_unslash($_GET['to'])) : '';
            echo '<div class="notice notice-' . ($tm_ok ? 'success' : 'error') . '"><p>' .
                ($tm_ok
                    ? 'テストメールを <strong>' . esc_html($tm_to) . '</strong> に送信しました。届かない場合は<strong>迷惑メールフォルダ</strong>も確認してください（届かない＝SPF/DKIM未設定の可能性大）。'
                    : 'テストメールの送信に失敗しました。WP Mail SMTP などの送信設定を確認してください。') .
                '</p></div>';
        } ?>
        <p>ページに <code>[fudosan_sateisho]</code> を貼ると査定書作成の受付フォームが表示されます。詳しい書き方は「<strong>使い方</strong>」タブへ。</p>
        <h2 class="nav-tab-wrapper" id="fss-tabs">
            <a href="#" class="nav-tab nav-tab-active" data-tab="basic">基本設定</a>
            <a href="#" class="nav-tab" data-tab="display">表示項目</a>
            <a href="#" class="nav-tab" data-tab="mail">自動返信メール</a>
            <a href="#" class="nav-tab" data-tab="style">デザイン</a>
            <a href="#" class="nav-tab" data-tab="usage">使い方</a>
        </h2>
        <form method="post" action="options.php">
            <?php settings_fields('fss_group'); ?>

            <div class="fss-tabpanel" data-tab="basic">
            <table class="form-table">
                <tr><th>サイト名</th><td><input type="text" name="<?php echo FSS_OPT; ?>[site_name]" value="<?php echo esc_attr(fss_opt('site_name', '査定書作成受付')); ?>" size="40">
                    <p class="description">メールの件名や差し込みに使われます。</p></td></tr>
                <tr><th>運営者名</th><td><input type="text" name="<?php echo FSS_OPT; ?>[operator_name]" value="<?php echo esc_attr(fss_opt('operator_name')); ?>" size="40"></td></tr>
                <tr><th>問い合わせ先</th><td><input type="text" name="<?php echo FSS_OPT; ?>[operator_contact]" value="<?php echo esc_attr(fss_opt('operator_contact')); ?>" size="40"></td></tr>
                <tr><th>送信元メール</th><td><input type="email" name="<?php echo FSS_OPT; ?>[from_email]" value="<?php echo esc_attr(fss_opt('from_email', get_option('admin_email'))); ?>" size="40">
                    <p class="description">お客様への受付完了メールの差出人。到達率のため WP Mail SMTP 等で SPF/DKIM を設定推奨。</p></td></tr>
                <tr><th>通知先メール（担当者）</th><td><input type="email" name="<?php echo FSS_OPT; ?>[notify_email]" value="<?php echo esc_attr(fss_opt('notify_email')); ?>" size="40">
                    <p class="description">受付が届いたら、このアドレスに通知します。空欄なら送信元メール（無ければ管理者アドレス）に通知します。</p></td></tr>
                <tr><th>プライバシーポリシーURL</th><td><input type="url" name="<?php echo FSS_OPT; ?>[privacy_url]" value="<?php echo esc_attr(fss_opt('privacy_url')); ?>" size="50"></td></tr>
                <tr><th>利用規約・免責URL</th><td><input type="url" name="<?php echo FSS_OPT; ?>[terms_url]" value="<?php echo esc_attr(fss_opt('terms_url')); ?>" size="50"></td></tr>
            </table>
            </div>

            <div class="fss-tabpanel" data-tab="display" style="display:none">
            <h3>フォームに表示する任意項目</h3>
            <table class="form-table"><tr><th>表示する項目</th><td>
                <label><input type="checkbox" name="<?php echo FSS_OPT; ?>[show_purpose]" value="1" <?php checked(fss_show('purpose')); ?>> 利用目的（売却検討・相続・離婚など）</label><br>
                <label><input type="checkbox" name="<?php echo FSS_OPT; ?>[show_marketing]" value="1" <?php checked(fss_show('marketing')); ?>> 「営業案内メールを希望」チェック欄</label>
                <p class="description">
                    物件の入力項目（マンション／戸建て／土地ごとの項目）は、選ばれた種別に応じて自動で切り替わります。<br>
                    ※ 物件種別・物件住所・メール・同意チェックは常に表示されます。
                </p>
            </td></tr></table>
            </div>

            <div class="fss-tabpanel" data-tab="mail" style="display:none">
            <table class="form-table">
                <tr><th>件名</th><td>
                    <input type="text" name="<?php echo FSS_OPT; ?>[mail_subject]" value="<?php echo esc_attr(fss_opt('mail_subject')); ?>" size="60" placeholder="【{site_name}】査定結果のお知らせ">
                    <p class="description">空欄で初期件名（【サイト名】査定結果のお知らせ）。</p>
                </td></tr>
                <tr><th>本文</th><td>
                    <textarea name="<?php echo FSS_OPT; ?>[mail_body]" rows="22" style="width:100%;max-width:760px;font-family:monospace;font-size:13px"><?php echo esc_textarea(fss_opt('mail_body') ?: fss_default_mail_body()); ?></textarea>
                    <p class="description">
                        空欄にして保存すると初期文面に戻ります。使える差し込みタグ：<br>
                        <code>{site_name}</code> <code>{property_details}</code>（物件情報のまとまり） <code>{ptype}</code> <code>{address}</code> <code>{purpose}</code> <code>{details}</code> <code>{operator_name}</code> <code>{operator_contact}</code>
                        <br><strong style="color:#b32d2e">※「鑑定評価ではない」旨の免責文は必ず残してください（法的に重要です）。</strong>
                    </p>
                </td></tr>
                <tr><th>到達確認</th><td>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fss_test_mail'), 'fss_test_mail')); ?>" class="button">テストメールを自分宛に送信</a>
                    <p class="description">
                        現在の件名・本文テンプレートでサンプルを送ります（保存してから押してください）。<br>
                        <strong>迷惑メールに入る場合</strong>は「WP Mail SMTP」等でSMTP送信にし、送信ドメインの <code>SPF</code> / <code>DKIM</code> / <code>DMARC</code> を設定してください。
                    </p>
                </td></tr>
            </table>
            </div>

            <div class="fss-tabpanel" data-tab="style" style="display:none">
            <h3>フォームの色</h3>
            <p class="description">保存すると、標準・compact・card・teaser すべてのフォームに反映されます。</p>
            <table class="form-table">
                <tr><th>ブランドカラー</th><td>
                    <input type="color" name="<?php echo FSS_OPT; ?>[color_brand]" value="<?php echo esc_attr(fss_opt('color_brand', '#1f6feb')); ?>">
                    <p class="description">ボタンの背景、査定価格の文字、入力済みチェック（✓）、次の入力欄のハイライトに使われます。</p>
                </td></tr>
                <tr><th>ボタンの文字色</th><td>
                    <input type="color" name="<?php echo FSS_OPT; ?>[color_btn_text]" value="<?php echo esc_attr(fss_opt('color_btn_text', '#ffffff')); ?>">
                </td></tr>
                <tr><th>見出しの色</th><td>
                    <input type="color" name="<?php echo FSS_OPT; ?>[color_title]" value="<?php echo esc_attr(fss_opt('color_title', '#1f6feb')); ?>">
                    <p class="description">teaser の見出し（例：60秒でかんたん入力！）の文字色。</p>
                </td></tr>
                <tr><th>「必須」バッジの色</th><td>
                    <input type="color" name="<?php echo FSS_OPT; ?>[color_badge]" value="<?php echo esc_attr(fss_opt('color_badge', '#ff5a36')); ?>">
                    <p class="description">未入力の項目に付くバッジ。入力すると「ブランドカラーの ✓」に変わります。</p>
                </td></tr>
            </table>
            <p class="description">初期値：ブランド <code>#1f6feb</code> ／ ボタン文字 <code>#ffffff</code> ／ 見出し <code>#1f6feb</code> ／ バッジ <code>#ff5a36</code></p>
            </div>

            <div class="fss-tabpanel" data-tab="usage" style="display:none">
            <h3>ショートコードの貼り方</h3>
            <p>受付フォームを出したい固定ページ・投稿・ウィジェット（カスタムHTML）に貼ります。</p>
            <table class="widefat striped" style="max-width:900px">
                <thead><tr><th style="width:170px">用途</th><th>ショートコード</th></tr></thead>
                <tbody>
                <tr><td><strong>標準</strong></td>
                    <td><code>[fudosan_sateisho]</code><br><span class="description">全項目・幅100%・枠なし。</span></td></tr>
                <tr><td><strong>コンパクト</strong><br><span class="description">サイドバー等</span></td>
                    <td><code>[fudosan_sateisho design="compact"]</code><br><span class="description">必須＋築年のみ・幅440pxのカード。</span></td></tr>
                <tr><td><strong>カード</strong></td>
                    <td><code>[fudosan_sateisho design="card"]</code><br><span class="description">全項目を枠＋影のカードで表示。</span></td></tr>
                </tbody>
            </table>
            <p class="description">属性：<code>button</code>（ボタン文言）が使えます。</p>

            <h3>受付後の動き</h3>
            <ol>
                <li>お客様に<strong>受付完了メール</strong>を自動返信（内容は「自動返信メール」タブで編集可）</li>
                <li><strong>通知先メール（担当者）</strong>に受付内容を通知（「基本設定」タブで指定）</li>
                <li>担当者が査定書を作成し、後日お客様のメール宛に送付</li>
            </ol>

            <h3>そのほか</h3>
            <ul style="list-style:disc;margin-left:20px">
                <li><strong>入力項目の増減・対象エリアの限定</strong> …「表示項目・対象エリア」タブ</li>
                <li><strong>メール文面（お客様向け）・テスト送信</strong> …「自動返信メール」タブ</li>
                <li><strong>ボタン色・見出し色</strong> …「デザイン」タブ</li>
                <li><strong>集まった受付データ</strong> … 左メニュー「査定書作成受付 → 受付一覧」（CSV書き出し／削除）</li>
            </ul>

            <h3 style="color:#b32d2e">法的な注意</h3>
            <p class="description">
                作成する査定書は宅建業の<strong>「価格査定（参考価格）」</strong>であり、不動産鑑定士による<strong>「鑑定評価」ではありません</strong>。
                フォーム・メールの免責文でその旨を明示しています。<strong>免責文は削除しないでください。</strong>公開前に弁護士等の確認を推奨します。
            </p>
            </div>

            <div id="fss-save"><?php submit_button(); ?></div>
        </form>
    </div>
    <script>
    (function(){
        var tabs = document.querySelectorAll('#fss-tabs .nav-tab');
        var panels = document.querySelectorAll('.fss-tabpanel');
        var save = document.getElementById('fss-save');
        tabs.forEach(function(t){
            t.addEventListener('click', function(e){
                e.preventDefault();
                tabs.forEach(function(x){ x.classList.remove('nav-tab-active'); });
                t.classList.add('nav-tab-active');
                var name = t.getAttribute('data-tab');
                panels.forEach(function(p){ p.style.display = (p.getAttribute('data-tab') === name) ? '' : 'none'; });
                if (save) save.style.display = (name === 'usage') ? 'none' : ''; // 使い方タブでは保存ボタンを隠す
            });
        });
    })();
    </script>
<?php }

function fss_leads_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'fudosan_sateisho_leads';
    $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC LIMIT 200");
    $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM $table");
    $export = wp_nonce_url(admin_url('admin-post.php?action=fss_export_leads'), 'fss_export_leads');
    echo '<div class="wrap"><h1>査定書作成受付 一覧</h1>';
    if (isset($_GET['deleted'])) echo '<div class="notice notice-success is-dismissible"><p>削除しました。</p></div>';
    $dberr = get_option('fss_last_db_error');
    if ($dberr) echo '<div class="notice notice-error"><p><strong>直近に保存エラーが発生しました：</strong> ' . esc_html($dberr) . '<br>最新版に更新すると自動修復を試みます。解消されない場合は、この赤いメッセージの文面を共有してください。</p></div>';
    echo '<p>受付件数：' . $total . ' 件（表示は最新200件）　<a class="button button-primary" href="' . esc_url($export) . '">CSVエクスポート（Excel）</a></p>';
    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>受付日時</th><th>メール</th><th>種別</th><th>物件住所</th><th>利用目的</th><th>詳細</th><th>営業可</th><th>操作</th></tr></thead><tbody>';
    if ($rows) foreach ($rows as $r) {
        $plabel = isset($GLOBALS['FSS_PTYPE_LABEL'][$r->ptype]) ? $GLOBALS['FSS_PTYPE_LABEL'][$r->ptype] : $r->ptype;
        $det = isset($r->details) ? (string)$r->details : '';
        $del = wp_nonce_url(admin_url('admin-post.php?action=fss_delete_lead&id=' . $r->id), 'fss_delete_lead_' . $r->id);
        printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td style="white-space:pre-line;font-size:12px;line-height:1.5">%s</td><td>%s</td><td><a href="%s" onclick="return confirm(\'この受付を削除しますか？\')" style="color:#b32d2e">削除</a></td></tr>',
            esc_html($r->created_at), esc_html($r->email), esc_html($plabel),
            esc_html((isset($r->address) && $r->address !== '') ? $r->address : '-'),
            esc_html((isset($r->purpose) && $r->purpose !== '') ? $r->purpose : '-'),
            esc_html($det !== '' ? $det : '-'),
            $r->marketing_opt_in ? '○' : '', esc_url($del));
    } else echo '<tr><td colspan="8">まだありません</td></tr>';
    echo '</tbody></table></div>';
}

/* CSVエクスポート（Excel向けShift_JIS） */
add_action('admin_post_fss_export_leads', 'fss_export_leads');
function fss_export_leads() {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    check_admin_referer('fss_export_leads');
    global $wpdb;
    $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}fudosan_sateisho_leads ORDER BY id DESC", ARRAY_A);
    nocache_headers();
    header('Content-Type: text/csv; charset=Shift_JIS');
    header('Content-Disposition: attachment; filename="sateisho_uketsuke.csv"');
    $out = fopen('php://output', 'w');
    $head = array('ID','受付日時','メール','種別','物件住所','利用目的','詳細','営業同意');
    $cols = array('id','created_at','email','ptype','address','purpose','details','marketing_opt_in');
    $sjis = function ($s) { return mb_convert_encoding((string)$s, 'SJIS-win', 'UTF-8'); };
    fputcsv($out, array_map($sjis, $head));
    foreach ($rows as $r) {
        $line = array();
        foreach ($cols as $c) {
            $v = isset($r[$c]) ? $r[$c] : '';
            if ($c === 'ptype' && isset($GLOBALS['FSS_PTYPE_LABEL'][$v])) $v = $GLOBALS['FSS_PTYPE_LABEL'][$v];
            $line[] = $sjis($v);
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

/* リード削除（個人情報の削除依頼対応） */
add_action('admin_post_fss_delete_lead', 'fss_delete_lead');
function fss_delete_lead() {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    $id = intval($_GET['id'] ?? 0);
    check_admin_referer('fss_delete_lead_' . $id);
    if ($id) {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'fudosan_sateisho_leads', array('id' => $id));
    }
    wp_safe_redirect(admin_url('admin.php?page=fudosan-sateisho-leads&deleted=1'));
    exit;
}

/* =========================================================================
 * 3. 都道府県・市区町村（国交省XIT002より生成した全国マスタ includes/jp-cities.php）
 * ======================================================================= */
function fss_jp_data() {
    static $d = null;
    if ($d === null) {
        $d = @include __DIR__ . '/includes/jp-cities.php';
        if (!is_array($d) || empty($d['prefs'])) $d = array('prefs' => array(), 'cities' => array());
    }
    return $d;
}
function fss_prefs()  { return fss_jp_data()['prefs']; }
function fss_cities() { return fss_jp_data()['cities']; }
function fss_city_name($pref, $code) {
    foreach (fss_cities()[$pref] ?? array() as $c) if ($c[0] === $code) return $c[1];
    return $code;
}

/* =========================================================================
 * 4. 査定エンジン（satei.py 移植）
 * ======================================================================= */
$GLOBALS['FSS_PTYPE_MAP']   = array('mansion' => '中古マンション等', 'house' => '宅地(土地と建物)', 'land' => '宅地(土地)');
$GLOBALS['FSS_PTYPE_LABEL'] = array('mansion' => '中古マンション', 'house' => '中古一戸建て（土地＋建物）', 'land' => '土地');
$GLOBALS['FSS_WAREKI']      = array('令和' => 2018, '平成' => 1988, '昭和' => 1925);

function fss_wareki_to_year($s) {
    if (!$s) return null;
    $s = trim($s);
    if (preg_match('/(令和|平成|昭和)\s*(元|\d+)年?/u', $s, $m)) {
        $n = ($m[2] === '元') ? 1 : intval($m[2]);
        return $GLOBALS['FSS_WAREKI'][$m[1]] + $n;
    }
    if (preg_match('/(\d{4})/', $s, $m)) return intval($m[1]);
    return null;
}

function fss_to_int($s) {
    if ($s === null || $s === '') return null;
    if (preg_match('/\d+/', str_replace(',', '', (string)$s), $m)) return intval($m[0]);
    return null;
}

function fss_unit_price($rec) {
    $up = fss_to_int($rec['UnitPrice'] ?? '');
    if ($up) return floatval($up);
    $price = fss_to_int($rec['TradePrice'] ?? '');
    $area  = fss_to_int($rec['Area'] ?? '');
    if ($price && $area && $area > 0) return $price / $area;
    return null;
}

function fss_percentile($sorted, $p) { // $sorted 昇順, $p:0..1（線形補間）
    $n = count($sorted);
    if ($n === 0) return 0;
    if ($n === 1) return $sorted[0];
    $rank = $p * ($n - 1);
    $lo = (int)floor($rank); $hi = (int)ceil($rank);
    if ($lo === $hi) return $sorted[$lo];
    return $sorted[$lo] + ($rank - $lo) * ($sorted[$hi] - $sorted[$lo]);
}

function fss_estimate($records, $ptype, $area, $year, $district = '') {
    $type = $GLOBALS['FSS_PTYPE_MAP'][$ptype] ?? '';
    $same = array_values(array_filter($records, function ($r) use ($type) {
        return ($r['Type'] ?? '') === $type;
    }));

    $filters = array();

    // ⓪ 地区（町名）フィルタ: 指定があり十分な件数があれば同じ地区に絞る（最も効く）
    if ($district !== '') {
        $dpool = array_values(array_filter($same, function ($r) use ($district) {
            return trim($r['DistrictName'] ?? '') === $district;
        }));
        if (count($dpool) >= 5) {
            $same = $dpool;
            $filters[] = sprintf('地区「%s」の事例', $district);
        }
    }

    // ① 面積帯フィルタ（対象±30%→±50%、件数を確保できる範囲で）
    $pool = $same;
    foreach (array(0.3, 0.5) as $frac) {
        $lo = $area * (1 - $frac); $hi = $area * (1 + $frac);
        $band = array_values(array_filter($same, function ($r) use ($lo, $hi) {
            $a = fss_to_int($r['Area'] ?? '');
            return $a !== null && $a >= $lo && $a <= $hi;
        }));
        if (count($band) >= 5) {
            $pool = $band;
            $filters[] = sprintf('面積が近い事例（%d〜%d㎡）', (int)$lo, (int)$hi);
            break;
        }
    }

    // ② 築年フィルタ（マンション・戸建のみ、±10→±20）
    if ($year && in_array($ptype, array('mansion', 'house'), true)) {
        foreach (array(10, 20) as $span) {
            $near = array_values(array_filter($pool, function ($r) use ($year, $span) {
                $y = fss_wareki_to_year($r['BuildingYear'] ?? '');
                return $y !== null && abs($y - $year) <= $span;
            }));
            if (count($near) >= 5) {
                $pool = $near;
                $filters[] = sprintf('築年が対象（%d年）±%d年の事例', $year, $span);
                break;
            }
        }
    }

    $units = array();
    foreach ($pool as $r) {
        $u = fss_unit_price($r);
        if ($u !== null && $u > 0) $units[] = $u;
    }

    if (count($units) < 5) {
        return array('ok' => false, 'sample_size' => count($units),
            'reason' => 'この地域・条件に近い取引事例が不足しているため、自動査定ができませんでした。個別査定をご利用ください。');
    }

    sort($units);
    $p25 = fss_percentile($units, 0.25);
    $med = fss_percentile($units, 0.5);
    $p75 = fss_percentile($units, 0.75);

    $reason = sprintf('周辺の%s成約事例のうち、条件の近い %d件の㎡単価をもとに、四分位（25%%〜75%%）でレンジを算出しました。採用した㎡単価の中央値は約 %s 円/㎡です。',
        $GLOBALS['FSS_PTYPE_LABEL'][$ptype], count($units), number_format((int)$med));
    if ($filters) $reason .= '（絞り込み: ' . implode(' ／ ', $filters) . '）';

    return array(
        'ok' => true,
        'low' => (int)($p25 * $area), 'mid' => (int)($med * $area), 'high' => (int)($p75 * $area),
        'unit_mid' => (int)$med, 'sample_size' => count($units), 'reason' => $reason,
    );
}

function fss_yen_man($v) { return number_format($v / 10000) . '万円'; }

/* =========================================================================
 * 5. API取得（reinfolib）＋モックフォールバック
 * ======================================================================= */
function fss_use_mock() {
    return fss_opt('use_mock') === '1' || fss_opt('api_key') === '';
}

function fss_fetch_records($city, $year, $quarter) {
    if (fss_use_mock()) return fss_mock_records($city);
    $url = add_query_arg(array('year' => $year, 'quarter' => $quarter, 'city' => $city), FSS_ENDPOINT);
    $res = wp_remote_get($url, array(
        'timeout' => 15,
        'headers' => array('Ocp-Apim-Subscription-Key' => fss_opt('api_key')),
    ));
    if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) return array();
    $body = json_decode(wp_remote_retrieve_body($res), true);
    return (is_array($body) && isset($body['data']) && is_array($body['data'])) ? $body['data'] : array();
}

function fss_fetch_recent($city, $latest_year, $quarters_back = 8) {
    $ck = 'fss_recs_' . $city . '_' . $latest_year . '_' . $quarters_back;
    $cached = get_transient($ck);
    if (is_array($cached)) return $cached;
    $recs = array(); $y = $latest_year; $q = 4;
    for ($i = 0; $i < $quarters_back; $i++) {
        $recs = array_merge($recs, fss_fetch_records($city, $y, $q));
        if (--$q === 0) { $q = 4; $y--; }
    }
    set_transient($ck, $recs, 12 * HOUR_IN_SECONDS);
    return $recs;
}

/* 市区町村内の地区名（町名）を、取引件数の多い順に返す: array(array(name, count), ...) */
function fss_districts($city) {
    $recs = fss_fetch_recent($city, intval(date('Y')) - 1, 8);
    $counts = array();
    foreach ($recs as $r) {
        $d = trim($r['DistrictName'] ?? '');
        if ($d !== '') $counts[$d] = (isset($counts[$d]) ? $counts[$d] : 0) + 1;
    }
    arsort($counts);
    $out = array();
    foreach ($counts as $name => $c) $out[] = array($name, $c);
    return $out;
}

add_action('wp_ajax_fudosan_sateisho_districts', 'fss_ajax_districts');
add_action('wp_ajax_nopriv_fudosan_sateisho_districts', 'fss_ajax_districts');
function fss_ajax_districts() {
    $city = sanitize_text_field($_GET['city'] ?? '');
    if (!$city) wp_send_json(array('districts' => array(), 'counts' => null));

    $recs = fss_fetch_recent($city, intval(date('Y')) - 1, 8);

    // 地区名（取引件数の多い順）
    $dc = array();
    foreach ($recs as $r) {
        $d = trim($r['DistrictName'] ?? '');
        if ($d !== '') $dc[$d] = (isset($dc[$d]) ? $dc[$d] : 0) + 1;
    }
    arsort($dc);
    $districts = array();
    foreach ($dc as $name => $c) $districts[] = array($name, $c);

    // 物件種別ごとの事例数（＝査定できるかの事前判定に使う）
    $counts = array('mansion' => 0, 'house' => 0, 'land' => 0);
    foreach ($recs as $r) {
        $t = $r['Type'] ?? '';
        foreach ($GLOBALS['FSS_PTYPE_MAP'] as $key => $val) {
            if ($t === $val) { $counts[$key]++; break; }
        }
    }
    wp_send_json(array('districts' => $districts, 'counts' => $counts));
}

function fss_mock_records($city) {
    $seed = ctype_digit(substr($city, -1)) ? intval(substr($city, -1)) : 3;
    $base = 700000 + $seed * 40000;
    $recs = array();
    $man = array(array(70,'令和3年',1.05),array(75,'平成28年',0.98),array(65,'平成22年',0.90),array(80,'令和5年',1.10),
                 array(72,'平成18年',0.85),array(68,'平成30年',1.00),array(85,'令和1年',1.02),array(60,'平成15年',0.80),
                 array(70,'平成27年',0.95),array(78,'令和2年',1.08));
    $dists = array('中央町', '南町', '北町');
    $i = 0;
    foreach ($man as $m) {
        $unit = (int)($base * $m[2]);
        $recs[] = array('Type'=>'中古マンション等','TradePrice'=>(string)((int)($m[0]*$unit)),'UnitPrice'=>'','Area'=>(string)$m[0],'BuildingYear'=>$m[1],'FloorPlan'=>'3LDK','Structure'=>'ＲＣ','DistrictName'=>$dists[$i++ % 3]);
    }
    foreach (array(array(110,'令和2年',42000000),array(130,'平成27年',38000000),array(100,'平成20年',33000000),array(150,'令和4年',52000000),array(120,'平成25年',40000000),array(105,'令和1年',36000000)) as $h) {
        $recs[] = array('Type'=>'宅地(土地と建物)','TradePrice'=>(string)$h[2],'UnitPrice'=>'','Area'=>(string)$h[0],'BuildingYear'=>$h[1],'Structure'=>'木造','DistrictName'=>$dists[$i++ % 3]);
    }
    foreach (array(array(120,28000000),array(140,31000000),array(100,24000000),array(165,37000000),array(110,26000000),array(130,30000000)) as $l) {
        $recs[] = array('Type'=>'宅地(土地)','TradePrice'=>(string)$l[1],'UnitPrice'=>'','Area'=>(string)$l[0],'BuildingYear'=>'','DistrictName'=>$dists[$i++ % 3]);
    }
    return $recs;
}

/* =========================================================================
 * 6. メール本文
 * ======================================================================= */
function fss_mail_body($ctx) {
    $tmpl = fss_opt('mail_body', '');
    if (trim($tmpl) === '') $tmpl = fss_default_mail_body();

    // 物件情報のまとまり（種別＋住所＋種別別の詳細）
    $pd = array();
    $pd[] = "■ 物件種別 : {$ctx['ptype_label']}";
    if (!empty($ctx['address'])) $pd[] = "■ 物件住所 : {$ctx['address']}";
    if (!empty($ctx['purpose'])) $pd[] = "■ 利用目的 : {$ctx['purpose']}";
    if (!empty($ctx['details']))  $pd[] = $ctx['details'];

    $repl = array(
        '{site_name}'        => fss_opt('site_name', '査定書作成受付'),
        '{property_details}' => implode("\n", $pd),
        '{ptype}'            => $ctx['ptype_label'],
        '{address}'          => isset($ctx['address']) ? $ctx['address'] : '',
        '{purpose}'          => isset($ctx['purpose']) ? $ctx['purpose'] : '',
        '{details}'          => isset($ctx['details']) ? $ctx['details'] : '',
        '{operator_name}'    => fss_opt('operator_name', ''),
        '{operator_contact}' => fss_opt('operator_contact', ''),
    );
    return strtr($tmpl, $repl);
}

/* 件名テンプレ */
function fss_mail_subject() {
    $s = fss_opt('mail_subject', '');
    if (trim($s) === '') $s = '【{site_name}】査定書作成のお申し込みを受け付けました';
    return strtr($s, array('{site_name}' => fss_opt('site_name', '査定書作成受付')));
}

/* テストメール送信（迷惑メール判定・文面の確認用） */
add_action('admin_post_fss_test_mail', 'fss_test_mail');
function fss_test_mail() {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    check_admin_referer('fss_test_mail');
    $to = wp_get_current_user()->user_email;
    $ctx = array(
        'ptype_label' => 'マンション', 'address' => '東京都渋谷区〇〇1-2-3', 'purpose' => '相続した・相続する予定',
        'details' => "■ マンション名 : 〇〇マンション\n■ 専有面積（㎡） : 70\n■ 築年（西暦） : 2015\n■ 間取り : 2LDK",
    );
    $headers = array('Content-Type: text/plain; charset=UTF-8');
    $from = fss_opt('from_email'); $site = fss_opt('site_name', '査定書作成受付');
    if ($from) $headers[] = 'From: ' . $site . ' <' . $from . '>';
    $ok = wp_mail($to, '[テスト] ' . fss_mail_subject(), fss_mail_body($ctx), $headers);
    wp_safe_redirect(admin_url('admin.php?page=fudosan-sateisho&testmail=' . ($ok ? '1' : '0') . '&to=' . rawurlencode($to)));
    exit;
}

/* =========================================================================
 * 7. AJAX（admin-ajax 経由。REST無効化環境でも動く）
 * ======================================================================= */
add_action('wp_ajax_fudosan_sateisho', 'fss_ajax');
add_action('wp_ajax_nopriv_fudosan_sateisho', 'fss_ajax');
function fss_ajax() {
    check_ajax_referer('fudosan_sateisho', 'nonce');

    $ptype   = sanitize_text_field($_POST['ptype'] ?? '');
    $address = sanitize_text_field($_POST['address'] ?? '');
    $email   = sanitize_email($_POST['email'] ?? '');
    $note    = sanitize_textarea_field($_POST['note_text'] ?? '');
    $purpose = sanitize_text_field($_POST['purpose'] ?? '');
    if ($purpose !== '' && !in_array($purpose, fss_purposes(), true)) $purpose = '';
    $agree = !empty($_POST['agree']);
    $mkt   = !empty($_POST['marketing']);

    $errors = array();
    if (!$agree) $errors[] = '個人情報の取扱いへの同意が必要です。';
    if (!is_email($email)) $errors[] = 'メールアドレスの形式が正しくありません。';
    if ($address === '') $errors[] = '物件の住所を入力してください。';
    if (!isset($GLOBALS['FSS_PTYPE_MAP'][$ptype])) $errors[] = '物件種別を選択してください。';

    // 選択された種別のスキーマで各項目を取得・検証し、詳細テキストを組み立てる
    $schema = fss_property_fields();
    $detail_lines = array();
    if (isset($schema[$ptype])) {
        foreach ($schema[$ptype] as $fd) {
            $post_key = $ptype . '__' . $fd['key'];
            if ($fd['type'] === 'check') {
                $val = !empty($_POST[$post_key]) ? 'はい' : '';
            } elseif ($fd['type'] === 'textarea') {
                $val = sanitize_textarea_field($_POST[$post_key] ?? '');
            } else {
                $val = sanitize_text_field($_POST[$post_key] ?? '');
            }
            // 選択肢は選択肢外を無視
            if ($fd['type'] === 'select' && $val !== '' && !in_array($val, fss_opt_list($fd['opts']), true)) $val = '';
            if (!empty($fd['req']) && $val === '') {
                $errors[] = '「' . $fd['label'] . '」を入力してください。';
            }
            if ($val !== '') $detail_lines[] = '■ ' . $fd['label'] . ' : ' . $val;
        }
    }
    if ($errors) wp_send_json(array('ok' => false, 'errors' => $errors));

    $label = $GLOBALS['FSS_PTYPE_LABEL'][$ptype];
    if ($note !== '') $detail_lines[] = '■ 備考 : ' . $note;
    $details = implode("\n", $detail_lines);

    // リード保存（受付）
    global $wpdb;
    $row = array(
        'created_at' => current_time('mysql'), 'email' => $email,
        'ptype' => $ptype, 'address' => $address, 'details' => $details,
        'purpose' => $purpose, 'marketing_opt_in' => $mkt ? 1 : 0,
    );
    $ins = $wpdb->insert($wpdb->prefix . 'fudosan_sateisho_leads', $row);
    if ($ins === false) {
        update_option('fss_last_db_error', $wpdb->last_error . ' @ ' . current_time('mysql'));
        fss_ensure_columns();
        $wpdb->insert($wpdb->prefix . 'fudosan_sateisho_leads', $row);
    } else {
        delete_option('fss_last_db_error');
    }

    $ctx = array(
        'ptype_label' => $label, 'address' => $address, 'details' => $details, 'purpose' => $purpose,
    );

    // 受付完了メール（お客様へ）
    $site = fss_opt('site_name', '査定書作成受付');
    $from = fss_opt('from_email');
    $headers = array('Content-Type: text/plain; charset=UTF-8');
    if ($from) $headers[] = 'From: ' . $site . ' <' . $from . '>';
    $mail_ok = wp_mail($email, fss_mail_subject(), fss_mail_body($ctx), $headers);

    // 管理者通知（通知先メール。無ければ送信元→管理者アドレス）
    $notify = fss_opt('notify_email', $from ?: get_option('admin_email'));
    if ($notify) {
        wp_mail($notify, '【査定書作成】新しい受付が届きました', fss_admin_notify_body($ctx, $email), $headers);
    }

    wp_send_json(array(
        'ok' => true, 'mail_ok' => (bool)$mail_ok, 'email' => $email,
        'ptype_label' => $label, 'address' => $address, 'details' => $details, 'purpose' => $purpose,
    ));
}

/* =========================================================================
 * 8. ショートコード [fudosan_sateisho]
 * ======================================================================= */
add_shortcode('fudosan_sateisho', 'fss_shortcode');
/**
 * デザインパターン:
 *   [fudosan_sateisho]                  標準（全項目・幅100%・枠なし）
 *   [fudosan_sateisho design="compact"] コンパクト（必須＋築年のみ・カード・幅440px。メインビジュアル横向け）
 *   [fudosan_sateisho design="card"]    全項目をカード（枠＋影）で表示
 */
function fss_shortcode($atts = array()) {
    $a = shortcode_atts(array(
        'design' => 'default', 'url' => '', 'button' => '', 'title' => '', 'subtitle' => '', 'logo' => '',
        'note' => '', 'fields' => '',
    ), $atts, 'fudosan_sateisho');
    $design = in_array($a['design'], array('default', 'compact', 'card'), true) ? $a['design'] : 'default';
    $compact = ($design === 'compact');
    $teaser  = false;   // 査定書受付ではティザー（ステップ）は使わない
    $target  = esc_url($a['url']);
    $btn     = $a['button'] !== '' ? sanitize_text_field($a['button']) : '査定書の作成を申し込む';
    $t_title = $a['title'] !== ''    ? sanitize_text_field($a['title'])    : '60秒でかんたん入力！';
    $t_sub   = $a['subtitle'] !== '' ? sanitize_text_field($a['subtitle']) : '査定結果はメールでお届けします';
    $t_logo  = $a['logo'] !== ''     ? esc_url($a['logo'])                 : '';
    $t_note  = $a['note'] !== ''     ? sanitize_text_field($a['note'])     : '';   // teaser下部の注記（既定は非表示）
    $t_fields = $teaser ? fss_parse_teaser_fields($a['fields']) : array();          // teaserに出す項目

    // 装飾（設定画面のデザインタブ）
    $c_brand    = fss_opt('color_brand', '#1f6feb');
    $c_btn_text = fss_opt('color_btn_text', '#ffffff');
    $c_title    = fss_opt('color_title', '#1f6feb');
    $c_badge    = fss_opt('color_badge', '#ff5a36');
    $c_brand_rgb = fss_hex_to_rgb($c_brand);

    // compact/teaser は入力を最小限に（compactでは築年は精度のため残す）
    $show_district   = fss_show('district')   && !$compact && !$teaser;
    $show_station    = fss_show('station')    && !$compact && !$teaser;
    $show_floor_plan = fss_show('floor_plan') && !$compact && !$teaser;
    $show_build_year = fss_show('build_year') && !$teaser;
    $show_purpose    = fss_show('purpose')    && !$compact && !$teaser;
    $show_marketing  = fss_show('marketing')  && !$compact && !$teaser;

    $prefs  = fss_area_prefs();
    $cities = fss_cities();
    $nonce  = wp_create_nonce('fudosan_sateisho');

    $ajax   = admin_url('admin-ajax.php');
    $year   = intval(date('Y'));

    // ステップ1から引き継いだ値（?fss_ptype=&fss_pref=&fss_city=&fss_purpose=&fss_area=&fss_build_year=）を検証
    $g = function ($k) { return isset($_GET[$k]) ? sanitize_text_field(wp_unslash($_GET[$k])) : ''; };
    $prefill = array(
        'ptype'      => $g('fss_ptype'),
        'pref'       => $g('fss_pref'),
        'city'       => $g('fss_city'),
        'purpose'    => $g('fss_purpose'),
        'area'       => $g('fss_area'),
        'build_year' => $g('fss_build_year'),
    );
    if (!isset($GLOBALS['FSS_PTYPE_MAP'][$prefill['ptype']])) $prefill['ptype'] = '';
    if (!isset($prefs[$prefill['pref']])) { $prefill['pref'] = ''; $prefill['city'] = ''; }
    if ($prefill['city'] !== '') {                                   // 市区町村コードが都道府県に属するか
        $ok = false;
        foreach (($cities[$prefill['pref']] ?? array()) as $c) { if ($c[0] === $prefill['city']) { $ok = true; break; } }
        if (!$ok) $prefill['city'] = '';
    }
    if ($prefill['purpose'] !== '' && !in_array($prefill['purpose'], fss_purposes(), true)) $prefill['purpose'] = '';
    if ($prefill['area'] !== '' && (!is_numeric($prefill['area']) || $prefill['area'] <= 0 || $prefill['area'] > 100000)) $prefill['area'] = '';
    if ($prefill['build_year'] !== '') {
        $by = intval($prefill['build_year']);
        $prefill['build_year'] = ($by >= 1950 && $by <= $year) ? (string)$by : '';
    }

    // 入力ガイド（必須→✓、次の欄を光らせる）の対象
    if ($teaser) {
        $reg = fss_teaser_fields();
        $required_names = array();
        foreach ($t_fields as $k) if ($reg[$k]['req']) $required_names[] = $reg[$k]['name'];
    } else {
        $required_names = array('ptype', 'pref_code', 'city_code', 'area', 'email');
    }
    $privacy = fss_opt('privacy_url');
    $terms   = fss_opt('terms_url');

    // プレースホルダーは「行動」を示す（ラベルと同じ語を繰り返さない）
    $pref_options = '<option value="">選択してください</option>';
    foreach ($prefs as $code => $name) $pref_options .= '<option value="' . esc_attr($code) . '">' . esc_html($name) . '</option>';

    $ptype_options = '<option value="">選択してください</option>'
        . '<option value="mansion">中古マンション</option>'
        . '<option value="house">中古一戸建て（土地＋建物）</option>'
        . '<option value="land">土地</option>';

    $agree_label = 'プライバシーポリシーおよび免責事項に同意します（必須）';
    if ($privacy || $terms) {
        $p = $privacy ? '<a href="' . esc_url($privacy) . '" target="_blank" rel="noopener">プライバシーポリシー</a>' : 'プライバシーポリシー';
        $t = $terms ? '<a href="' . esc_url($terms) . '" target="_blank" rel="noopener">免責事項</a>' : '免責事項';
        $agree_label = $p . 'および' . $t . 'に同意します（必須）';
    }

    $cities_json = wp_json_encode($cities, JSON_UNESCAPED_UNICODE);
    $uid = 'fss-' . uniqid();

    ob_start(); ?>
<div class="fss-wrap fss-design-<?php echo esc_attr($design); ?>" id="<?php echo esc_attr($uid); ?>">
  <style>
    .fss-wrap{--fss-brand:<?php echo esc_attr($c_brand); ?>;--fss-brand-rgb:<?php echo esc_attr($c_brand_rgb); ?>;--fss-btn-text:<?php echo esc_attr($c_btn_text); ?>;--fss-title:<?php echo esc_attr($c_title); ?>;--fss-badge-bg:<?php echo esc_attr($c_badge); ?>;--fss-ink:#1a1f36;--fss-muted:#6b7280;--fss-line:#e5e7eb;width:100%;max-width:none;margin:0;color:var(--fss-ink);font-family:inherit;line-height:1.75;font-size:17px}
    .fss-card{background:transparent;border:0;border-radius:0;padding:0}
    .fss-wrap label{display:block;font-weight:600;margin:18px 0 7px;font-size:19px}
    /* 必須／任意バッジ */
    .fss-req,.fss-opt{font-size:11px;font-weight:700;border-radius:4px;padding:4px 7px;line-height:1;margin-left:8px;display:inline-flex;align-items:center;vertical-align:middle;letter-spacing:.02em;white-space:nowrap;flex:0 0 auto}
    .fss-req{background:var(--fss-badge-bg);color:#fff}
    .fss-opt{background:#eef1f5;color:#6b7280}
    .fss-req.fss-done{background:var(--fss-brand);color:#fff;border-radius:50%;width:20px;height:20px;padding:0;font-size:12px;justify-content:center}

    /* 引き継ぎ時の「続きはこちらから」バナー */
    .fss-resume{display:flex;align-items:baseline;flex-wrap:wrap;gap:4px 10px;background:rgba(var(--fss-brand-rgb),.07);border:1px solid rgba(var(--fss-brand-rgb),.22);border-left:4px solid var(--fss-brand);border-radius:8px;padding:12px 14px;margin:26px 0 6px;font-size:15px}
    .fss-resume b{color:var(--fss-brand);font-weight:800}
    .fss-resume span{color:var(--fss-muted);font-size:14px}

    /* セクション見出し（メリハリ） */
    .fss-section{display:flex;align-items:center;font-weight:800;font-size:17px;color:var(--fss-ink);margin:32px 0 4px;padding-left:11px;border-left:4px solid var(--fss-brand);line-height:1.5}
    .fss-form > .fss-section:first-child{margin-top:0}

    .fss-wrap input,.fss-wrap select{width:100%;padding:14px 15px;border:1px solid #cbd5e1;border-radius:9px;font-size:18px;background:#fff;box-sizing:border-box;transition:border-color .15s,box-shadow .15s}
    .fss-wrap input:focus,.fss-wrap select:focus{outline:none;border-color:var(--fss-brand);box-shadow:0 0 0 3px rgba(var(--fss-brand-rgb),.15)}
    .fss-row{display:flex;gap:12px}.fss-row>div{flex:1}
    .fss-hint{color:var(--fss-muted);font-size:15px;margin-top:5px;line-height:1.7}
    .fss-check{display:flex;gap:9px;align-items:flex-start;margin-top:14px}
    .fss-check input{width:auto;margin-top:6px;transform:scale(1.2)}.fss-check label{margin:0;font-weight:400;font-size:16px}
    .fss-wrap button{margin-top:24px;width:100%;background:var(--fss-brand);color:var(--fss-btn-text);border:0;border-radius:10px;padding:18px;font-size:20px;font-weight:700;cursor:pointer}
    .fss-wrap button:hover{filter:brightness(.93)}
    .fss-wrap button:disabled{opacity:.6;cursor:wait;filter:none}
    .fss-disc{background:#fff8e6;border:1px solid #f0e0a8;border-radius:10px;padding:15px 17px;font-size:14px;color:#6b5a12;margin-top:18px}
    .fss-err{background:#fdecea;border:1px solid #f5c6cb;color:#c0392b;padding:10px 12px;border-radius:9px;margin-bottom:10px;font-size:16px}
    .fss-price{font-size:34px;font-weight:800;color:var(--fss-brand);text-align:center;margin:8px 0}
    .fss-mid{text-align:center;color:var(--fss-muted);font-size:16px}
    .fss-spec{width:100%;border-collapse:collapse;margin:16px 0;font-size:17px}
    .fss-spec th,.fss-spec td{border-bottom:1px solid var(--fss-line);padding:12px 10px;text-align:left}
    .fss-spec th{color:var(--fss-muted);font-weight:600;width:38%}
    .fss-ok{color:#0a7d33;font-weight:600;font-size:16px}
    .fss-coverage{color:var(--fss-muted);font-size:14px;line-height:1.6;margin-top:8px}

    /* デザイン: compact / teaser（メインビジュアル横などに収める短い版） */
    .fss-design-compact,.fss-design-teaser{max-width:440px}
    .fss-design-compact .fss-card,.fss-design-teaser .fss-card{background:#fff;border:1px solid var(--fss-line);border-radius:14px;padding:20px 18px;box-shadow:0 8px 28px rgba(16,24,40,.10)}
    .fss-design-compact label,.fss-design-teaser label{font-size:16px;margin:12px 0 5px}
    .fss-design-compact input,.fss-design-compact select,.fss-design-teaser input,.fss-design-teaser select{padding:11px 12px;font-size:16px}
    .fss-design-compact button,.fss-design-teaser button{margin-top:16px;padding:14px;font-size:17px}
    .fss-design-compact .fss-form .fss-hint,.fss-design-teaser .fss-form .fss-hint{display:none}
    .fss-design-compact .fss-section{display:none} /* 短くするため見出しは省略 */
    .fss-design-compact .fss-coverage,.fss-design-teaser .fss-coverage{font-size:13px;margin-top:6px}
    .fss-design-compact .fss-check label{font-size:14px}
    .fss-design-compact .fss-disc{font-size:12px;padding:10px 12px;margin-top:12px}
    .fss-design-compact .fss-price{font-size:28px}
    .fss-design-compact .fss-spec{font-size:15px}
    .fss-design-compact .fss-spec th,.fss-design-compact .fss-spec td{padding:9px 8px}
    .fss-design-teaser .fss-note{color:var(--fss-muted);font-size:12px;margin-top:10px;line-height:1.6}

    /* teaser: ヘッダー */
    .fss-design-teaser .fss-card{padding:22px 20px}
    .fss-teaser-head{text-align:center;padding-bottom:14px;margin-bottom:6px;border-bottom:1px solid var(--fss-line)}
    .fss-teaser-title{font-size:19px;font-weight:800;color:var(--fss-title);line-height:1.4}
    .fss-teaser-sub{font-size:13px;color:var(--fss-muted);margin-top:4px}
    /* ロゴあり: ロゴ左・テキスト右の横並び */
    .fss-teaser-head.fss-has-logo{display:flex;align-items:center;gap:12px;text-align:left}
    .fss-teaser-head.fss-has-logo .fss-teaser-logo{flex:0 0 auto;line-height:0}
    .fss-teaser-head.fss-has-logo .fss-teaser-logo img{display:block;max-height:56px;max-width:80px;width:auto;height:auto}
    .fss-teaser-head.fss-has-logo .fss-teaser-texts{flex:1;min-width:0}
    @media (max-width:380px){
      .fss-teaser-head.fss-has-logo{flex-direction:column;text-align:center;gap:8px}
    }

    /* teaser: ラベル横並び＋必須バッジ */
    .fss-design-teaser .fss-trow{display:flex;align-items:center;gap:10px;margin:14px 0}
    .fss-design-teaser .fss-tlabel{flex:0 0 auto;width:142px;display:flex;align-items:center;gap:6px;font-weight:700;font-size:15px;line-height:1.35}
    .fss-design-teaser .fss-tfield{flex:1;min-width:0}
    .fss-design-teaser .fss-tfield select,.fss-design-teaser .fss-tfield input{margin:0}
    .fss-design-teaser .fss-tlabel .fss-req,.fss-design-teaser .fss-tlabel .fss-opt{margin-left:0}
    .fss-badge{background:var(--fss-badge-bg);color:#fff;font-size:11px;font-weight:700;border-radius:4px;padding:4px 7px;line-height:1;flex:0 0 auto;white-space:nowrap}
    .fss-badge.fss-done{background:var(--fss-brand);border-radius:50%;width:21px;height:21px;padding:0;font-size:12px;display:inline-flex;align-items:center;justify-content:center}

    /* 次に入力すべき欄をハイライト（全デザイン共通） */
    .fss-wrap select.fss-next,.fss-wrap input.fss-next{border-color:rgba(var(--fss-brand-rgb),.55);animation:fsPulse 1.5s ease-in-out infinite}
    @keyframes fsPulse{
      0%,100%{box-shadow:0 0 0 3px rgba(var(--fss-brand-rgb),.16)}
      50%{box-shadow:0 0 0 7px rgba(var(--fss-brand-rgb),.28)}
    }
    @media (prefers-reduced-motion:reduce){
      .fss-wrap select.fss-next,.fss-wrap input.fss-next{animation:none;box-shadow:0 0 0 3px rgba(var(--fss-brand-rgb),.20)}
    }

    /* デザイン: card（全項目を枠＋影のカードで） */
    .fss-design-card .fss-card{background:#fff;border:1px solid var(--fss-line);border-radius:14px;padding:24px 22px;box-shadow:0 4px 18px rgba(16,24,40,.06)}
  </style>

  <div class="fss-card fss-form-card" id="fss-form-card">
    <div class="fss-errors" id="fss-errors"></div>
<?php if ($teaser): ?>
    <div class="fss-teaser-head<?php echo $t_logo ? ' fss-has-logo' : ''; ?>">
<?php if ($t_logo): ?>
      <div class="fss-teaser-logo"><img src="<?php echo esc_url($t_logo); ?>" alt="<?php echo esc_attr(fss_opt('site_name', '')); ?>"></div>
<?php endif; ?>
      <div class="fss-teaser-texts">
        <div class="fss-teaser-title"><?php echo esc_html($t_title); ?></div>
<?php if ($t_sub !== ''): ?>
        <div class="fss-teaser-sub"><?php echo esc_html($t_sub); ?></div>
<?php endif; ?>
      </div>
    </div>
    <form class="fss-form" id="fss-form">
<?php $reg = fss_teaser_fields(); foreach ($t_fields as $k): $fd = $reg[$k]; ?>
      <div class="fss-trow">
        <div class="fss-tlabel"><?php echo esc_html($fd['label']); ?><?php
            echo $fd['req'] ? '<span class="fss-badge">必須</span>' : '<span class="fss-opt">任意</span>'; ?></div>
        <div class="fss-tfield">
<?php if ($k === 'purpose'): ?>
          <select name="purpose">
            <option value="">選択してください</option>
<?php foreach (fss_purposes() as $p): ?>
            <option value="<?php echo esc_attr($p); ?>"><?php echo esc_html($p); ?></option>
<?php endforeach; ?>
          </select>
<?php elseif ($k === 'ptype'): ?>
          <select name="ptype" required><?php echo $ptype_options; ?></select>
<?php elseif ($k === 'pref'): ?>
          <select class="fss-pref" name="pref_code" id="fss-pref" required><?php echo $pref_options; ?></select>
<?php elseif ($k === 'city'): ?>
          <select class="fss-city" name="city_code" id="fss-city" required><option value="">先に都道府県を選択</option></select>
<?php elseif ($k === 'area'): ?>
          <input type="number" name="area" step="0.01" min="1" placeholder="例：70" required>
<?php elseif ($k === 'build_year'): ?>
          <input type="number" name="build_year" min="1950" max="<?php echo $year; ?>" placeholder="例：2015">
<?php endif; ?>
        </div>
      </div>
<?php endforeach; ?>

      <div class="fss-coverage"></div>
<?php else: ?>
    <form class="fss-form" id="fss-form">
<?php if ($show_purpose): ?>
      <div class="fss-section">ご利用目的</div>
      <label>どのようなご事情ですか<span class="fss-opt">任意</span></label>
      <select name="purpose">
        <option value="">選択してください</option>
<?php foreach (fss_purposes() as $p): ?>
        <option value="<?php echo esc_attr($p); ?>"><?php echo esc_html($p); ?></option>
<?php endforeach; ?>
      </select>
<?php endif; ?>

      <div class="fss-section">物件の情報</div>
      <label>物件種別<span class="fss-req">必須</span></label>
      <select name="ptype" id="fss-ptype" required><?php echo $ptype_options; ?></select>

      <label>物件の住所<span class="fss-req">必須</span></label>
      <input type="text" name="address" placeholder="例：東京都渋谷区〇〇1-2-3" required>
      <div class="fss-hint">丁目・番地までご記入ください（建物名・部屋番号は下の欄で結構です）</div>

<?php foreach (fss_property_fields() as $pt => $flds): ?>
      <div class="fss-group" data-ptype="<?php echo esc_attr($pt); ?>" style="display:none">
<?php foreach ($flds as $fd): $nm = $pt . '__' . $fd['key']; ?>
<?php if ($fd['type'] === 'check'): ?>
        <div class="fss-check">
          <input type="checkbox" name="<?php echo esc_attr($nm); ?>" id="<?php echo esc_attr($nm); ?>" value="1">
          <label for="<?php echo esc_attr($nm); ?>"><?php echo esc_html($fd['chk']); ?></label>
        </div>
<?php else: ?>
        <label><?php echo esc_html($fd['label']); ?><?php echo $fd['req'] ? '<span class="fss-req">必須</span>' : '<span class="fss-opt">任意</span>'; ?></label>
<?php if ($fd['type'] === 'select'): ?>
        <select name="<?php echo esc_attr($nm); ?>" class="fss-typed" data-req="<?php echo $fd['req'] ? '1' : ''; ?>">
          <option value="">選択してください</option>
<?php foreach (fss_opt_list($fd['opts']) as $o): ?>
          <option value="<?php echo esc_attr($o); ?>"><?php echo esc_html($o); ?></option>
<?php endforeach; ?>
        </select>
<?php elseif ($fd['type'] === 'textarea'): ?>
        <textarea name="<?php echo esc_attr($nm); ?>" class="fss-typed" data-req="" rows="2" placeholder="<?php echo esc_attr(isset($fd['ph']) ? $fd['ph'] : ''); ?>"></textarea>
<?php else: ?>
        <input type="<?php echo $fd['type'] === 'number' ? 'number' : 'text'; ?>"<?php echo $fd['type'] === 'number' ? ' step="any" min="0"' : ''; ?> name="<?php echo esc_attr($nm); ?>" class="fss-typed" data-req="<?php echo $fd['req'] ? '1' : ''; ?>" placeholder="<?php echo esc_attr(isset($fd['ph']) ? $fd['ph'] : ''); ?>">
<?php endif; ?>
<?php endif; ?>
<?php endforeach; ?>
      </div>
<?php endforeach; ?>

      <label>備考・その他<span class="fss-opt">任意</span></label>
      <textarea name="note_text" rows="2" placeholder="複数の接道がある場合など、補足があればご記入ください"></textarea>
<?php endif; ?>

<?php if (!$teaser): ?>
      <div class="fss-section">ご連絡先</div>
      <label>結果をお届けするメールアドレス<span class="fss-req">必須</span></label>
      <input type="email" name="email" placeholder="you@example.com" required>

      <div class="fss-check">
        <input type="checkbox" name="agree" id="fss-agree" value="1" required>
        <label for="fss-agree"><?php echo $agree_label; ?></label>
      </div>
<?php endif; ?>
<?php if ($show_marketing): ?>
      <div class="fss-check">
        <input type="checkbox" name="marketing" id="fss-mkt" value="1">
        <label for="fss-mkt">売却に関するご提案・お役立ち情報のメール受け取りを希望します（任意）</label>
      </div>
<?php endif; ?>

      <button class="fss-submit" type="submit" id="fss-submit"><?php echo esc_html($btn); ?></button>
    </form>

    <div class="fss-disc">
      作成する査定書は宅建業者による<strong>参考価格（価格査定）</strong>であり、不動産鑑定士による<strong>鑑定評価ではありません</strong>。実際の売却価格を保証するものではありません。
    </div>
  </div>

  <div class="fss-card fss-result" id="fss-result" style="display:none"></div>
</div>

<script>
(function(){
  var CITIES = <?php echo $cities_json; ?>;
  var AJAX = <?php echo wp_json_encode($ajax); ?>;
  var NONCE = <?php echo wp_json_encode($nonce); ?>;
  var WRAP_ID = <?php echo wp_json_encode($uid); ?>;
  var TEASER = <?php echo $teaser ? 'true' : 'false'; ?>;
  var TARGET = <?php echo wp_json_encode($target); ?>;
  var PREFILL = <?php echo wp_json_encode($prefill); ?>;

  function init(){
  var wrap = document.getElementById(WRAP_ID);
  if (!wrap || wrap.getAttribute('data-fss-init')) return;
  wrap.setAttribute('data-fss-init', '1');
  var pref = wrap.querySelector('.fss-pref'), city = wrap.querySelector('.fss-city');
  var district = wrap.querySelector('.fss-district');
  var form = wrap.querySelector('.fss-form'), errBox = wrap.querySelector('.fss-errors');
  var formCard = wrap.querySelector('.fss-form-card'), resultCard = wrap.querySelector('.fss-result');
  var btn = wrap.querySelector('.fss-submit');
  var SUBMIT_LABEL = btn ? btn.textContent : '送信';

  var ptypeSel = wrap.querySelector('select[name="ptype"]');
  var cov = wrap.querySelector('.fss-coverage');
  var TYPE_COUNTS = null;
  var TYPE_LABELS = { mansion:'中古マンション', house:'一戸建て', land:'土地' };

  // （査定書受付では未使用。安全のため空実装で残す）
  function renderCoverage(){
    if (!cov) return;
    if (!TYPE_COUNTS) { cov.innerHTML = ''; return; }
    var parts = [];
    for (var k in TYPE_LABELS) parts.push(TYPE_LABELS[k] + ' ' + (TYPE_COUNTS[k] || 0) + '件');
    var html = 'この地域の取引事例：' + parts.join(' ／ ');
    var t = ptypeSel ? ptypeSel.value : '';
    if (t && (TYPE_COUNTS[t] || 0) < 5) {
      html += '<br><strong style="color:#c0392b">⚠ 選択中の種別は事例が少ないため、査定できない場合があります（その場合は個別査定をご案内します）。</strong>';
    }
    cov.innerHTML = html;
  }

  // 物件種別ごとの項目グループ
  var groups = wrap.querySelectorAll('.fss-group');

  // 直前の <label> 内の「必須」バッジ
  function badgeFor(el){
    var lbl = el.previousElementSibling;
    return (lbl && lbl.tagName === 'LABEL') ? lbl.querySelector('.fss-req') : null;
  }

  // 画面の並び順に：種別 → 住所 → 選択中の種別の必須項目 → メール
  function currentRequired(){
    var req = [];
    if (form.elements['ptype'])   req.push(form.elements['ptype']);
    if (form.elements['address']) req.push(form.elements['address']);
    var pt = ptypeSel ? ptypeSel.value : '';
    if (pt) {
      var g = wrap.querySelector('.fss-group[data-ptype="' + pt + '"]');
      if (g) Array.prototype.forEach.call(g.querySelectorAll('[data-req="1"]'), function(el){ req.push(el); });
    }
    if (form.elements['email'])   req.push(form.elements['email']);
    return req;
  }

  var resumeBox = null;

  function updateFormState(){
    Array.prototype.forEach.call(form.querySelectorAll('.fss-next'), function(e){ e.classList.remove('fss-next'); });
    var req = currentRequired(), firstEmpty = null, remaining = 0;
    req.forEach(function(el){
      var b = badgeFor(el);
      var filled = !!(el.value && String(el.value).trim() !== '');
      if (b) {
        if (filled) { b.classList.add('fss-done'); b.textContent = '✓'; }
        else { b.classList.remove('fss-done'); b.textContent = '必須'; }
      }
      if (!filled) { remaining++; if (!firstEmpty) firstEmpty = el; }
    });
    if (firstEmpty) firstEmpty.classList.add('fss-next');
    if (resumeBox) {
      if (remaining === 0) resumeBox.style.display = 'none';
      else { resumeBox.style.display = ''; resumeBox.querySelector('span').textContent = 'あと' + remaining + '項目で完了です'; }
    }
  }

  function insertResumeBanner(){
    var el = wrap.querySelector('.fss-next');
    if (!el) return;
    var anchor = el;
    while (anchor && anchor.parentNode !== form) anchor = anchor.parentNode;
    if (!anchor) return;
    var prev = anchor.previousElementSibling;
    if (prev && prev.tagName === 'LABEL') anchor = prev;
    resumeBox = document.createElement('div');
    resumeBox.className = 'fss-resume';
    resumeBox.innerHTML = '<b>↓ 続きはこちらから</b><span></span>';
    form.insertBefore(resumeBox, anchor);
    updateFormState();
  }

  // 種別を選ぶと、その種別の入力欄だけ表示
  function switchType(){
    var pt = ptypeSel ? ptypeSel.value : '';
    Array.prototype.forEach.call(groups, function(g){
      g.style.display = (g.getAttribute('data-ptype') === pt) ? '' : 'none';
    });
    updateFormState();
  }

  if (ptypeSel) ptypeSel.addEventListener('change', switchType);
  Array.prototype.forEach.call(form.querySelectorAll('.fss-typed, input[name="address"], input[name="email"]'), function(el){
    el.addEventListener('change', updateFormState);
    el.addEventListener('input', updateFormState);
  });

  switchType(); // 初期表示（種別グループ反映＋ガイド）

  // 引き継ぎで来たときは、最初の未入力欄（＝光っている欄）まで自動スクロール
  function scrollToFirstEmpty(){
    var target = resumeBox || wrap.querySelector('.fss-next') || btn;
    if (!target) return;
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    // 市区町村の非同期読込でレイアウトが動くため、少し待ってから
    setTimeout(function(){
      target.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center' });
    }, 120);
  }

  // ステップ1（teaser）から引き継いだ値を復元し、市区町村・事例数まで自動で読み込む
  if (!TEASER && PREFILL) {
    var hasPrefill = Object.keys(PREFILL).some(function(k){ return !!PREFILL[k]; });
    ['purpose','area','build_year'].forEach(function(n){
      var el = form.elements[n];
      if (el && PREFILL[n]) el.value = PREFILL[n];
    });
    if (PREFILL.ptype && ptypeSel) ptypeSel.value = PREFILL.ptype;
    if (PREFILL.pref && pref) {
      pref.value = PREFILL.pref;
      pref.dispatchEvent(new Event('change'));
      if (PREFILL.city && city) {
        city.value = PREFILL.city;
        city.dispatchEvent(new Event('change'));
      }
    }
    updateFormState();
    if (hasPrefill) {                       // 引き継ぎ時のみ（通常の直アクセスでは動かさない）
      insertResumeBanner();
      scrollToFirstEmpty();
    }
  }

  function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':s; return d.innerHTML; }

  form.addEventListener('submit', function(e){
    e.preventDefault();

    // ステップ1（teaser）: 入力値をURLに載せてフル入力フォームへ引き継ぐ
    if (TEASER) {
      if (!TARGET) return;
      var MAP = { ptype:'fss_ptype', pref_code:'fss_pref', city_code:'fss_city',
                  purpose:'fss_purpose', area:'fss_area', build_year:'fss_build_year' };
      var p = [];
      Object.keys(MAP).forEach(function(n){
        var el = form.elements[n];
        if (el && el.value) p.push(MAP[n] + '=' + encodeURIComponent(el.value));
      });
      window.location.href = TARGET + (TARGET.indexOf('?') >= 0 ? '&' : '?') + p.join('&');
      return;
    }

    errBox.innerHTML = '';
    btn.disabled = true; btn.textContent = '送信中…';

    var fd = new FormData(form);
    fd.append('action', 'fudosan_sateisho');
    fd.append('nonce', NONCE);

    fetch(AJAX, { method:'POST', body: fd, credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(d){
        btn.disabled = false; btn.textContent = SUBMIT_LABEL;
        if (d.errors) { errBox.innerHTML = d.errors.map(function(x){return '<div class="fss-err">'+esc(x)+'</div>';}).join(''); return; }
        renderResult(d);
      })
      .catch(function(){
        btn.disabled = false; btn.textContent = SUBMIT_LABEL;
        errBox.innerHTML = '<div class="fss-err">通信エラーが発生しました。時間をおいて再度お試しください。</div>';
      });
  });

  function renderResult(d){
    var rows = '<tr><th>物件種別</th><td>'+esc(d.ptype_label)+'</td></tr>'
      + (d.address ? '<tr><th>物件住所</th><td>'+esc(d.address)+'</td></tr>' : '')
      + (d.purpose ? '<tr><th>利用目的</th><td>'+esc(d.purpose)+'</td></tr>' : '');
    var det = '';
    if (d.details) {
      det = '<div class="fss-hint" style="white-space:pre-line;margin-top:10px">'+esc(d.details)+'</div>';
    }
    var mailLine = d.mail_ok
      ? '<p class="fss-ok">✓ '+esc(d.email)+' 宛に受付完了メールをお送りしました。</p>'
      : '<p class="fss-hint">受付は完了しています（確認メールの送信に失敗した可能性があります。担当より別途ご連絡します）。</p>';
    var html = '<h3 style="margin-top:0">査定書作成のお申し込みを受け付けました</h3>'
      + '<p>以下の内容で受け付けました。<strong>担当者が査定書を作成し、後日メールでお送りします。</strong></p>'
      + '<table class="fss-spec">'+rows+'</table>'
      + det
      + mailLine
      + '<div class="fss-disc">作成する査定書は宅建業者による<strong>参考価格（価格査定）</strong>であり、不動産鑑定士による<strong>鑑定評価ではありません</strong>。実際の売却価格を保証するものではありません。</div>';
    resultCard.innerHTML = html;
    formCard.style.display = 'none';
    resultCard.style.display = 'block';
    resultCard.scrollIntoView({ behavior:'smooth', block:'start' });
  }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
</script>
<?php
    return ob_get_clean();
}

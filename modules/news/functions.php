<?php
/** Мэдээ мэдээлэл (blog) модулийн туслах функцууд */

require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/notifications.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/includes/mailer.php';

const NEWS_CATEGORIES = [
    'notice' => ['label' => 'Мэдэгдэл',    'cls' => 'bg-orange-50 text-[#c9481f] dark:bg-[#2a1810] dark:text-[#ff8a5c]'],
    'event'  => ['label' => 'Үйл явдал',   'cls' => 'bg-blue-50 text-blue-700 dark:bg-[#1c2a3d] dark:text-[#6ea8fe]'],
    'hr'     => ['label' => 'Хүний нөөц',  'cls' => 'bg-violet-50 text-violet-700 dark:bg-violet-950/30 dark:text-violet-300'],
    'safety' => ['label' => 'ХАБЭА',       'cls' => 'bg-amber-50 text-amber-700 dark:bg-amber-950/30 dark:text-amber-300'],
    'general'=> ['label' => 'Ерөнхий',     'cls' => 'bg-slate-100 text-slate-600 dark:bg-[#272c38] dark:text-[#c9cdd6]'],
];

/** Нийтлэх эрх — superadmin эсвэл "Нийтлэгчид"-ээс news.publish олгогдсон */
function newsCanPublish(): bool {
    return isSuperAdmin() || hasPermission('news.publish');
}

/** Ажилтны нэгж болон бүх дээд нэгжийн id (нийтлэл харагдах эсэхийг шалгахад) */
function newsUnitChain(PDO $pdo, ?int $orgUnitId): array {
    $chain = []; $guard = 0;
    $stmt = $pdo->prepare("SELECT parent_id FROM org_units WHERE id = ?");
    while ($orgUnitId && $guard++ < 20) {
        $chain[] = $orgUnitId;
        $stmt->execute([$orgUnitId]);
        $orgUnitId = ($p = $stmt->fetchColumn()) ? (int)$p : null;
    }
    return $chain;
}

/** Нэгжүүд болон бүх дэд нэгжийн id (нийтлэлийн хүлээн авагчдыг тодорхойлоход) */
function newsUnitDescendants(PDO $pdo, array $unitIds): array {
    $all = []; $queue = array_map('intval', $unitIds); $guard = 0;
    $stmt = $pdo->prepare("SELECT id FROM org_units WHERE parent_id = ?");
    while ($queue && $guard++ < 1000) {
        $id = array_shift($queue);
        if (in_array($id, $all, true)) continue;
        $all[] = $id;
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $child) $queue[] = (int)$child;
    }
    return $all;
}

/**
 * Тухайн ажилтанд харагдах нийтлэлийн WHERE нөхцөл (p alias) + параметрүүд.
 * Нийтлэгдсэн + цаг нь болсон + (бүгдэд эсвэл өөрийн/дээд нэгжид чиглэсэн). Зохиогч өөрийнхөө бүгдийг харна.
 */
function newsVisibilityWhere(PDO $pdo, int $employeeId, ?int $orgUnitId): array {
    $chain = newsUnitChain($pdo, $orgUnitId);
    $unitCond = $chain
        ? "OR EXISTS (SELECT 1 FROM news_post_units pu WHERE pu.post_id = p.id AND pu.org_unit_id IN (" . implode(',', array_fill(0, count($chain), '?')) . "))"
        : '';
    return [
        "((p.status = 'published' AND p.published_at <= NOW() AND (p.audience = 'all' $unitCond)) OR p.author_id = ?)",
        array_merge($chain, [$employeeId]),
    ];
}

/** Нийтлэлийн хүлээн авагчдын SELECT (notifyBySql-д) */
function newsAudienceSql(PDO $pdo, array $post): array {
    if ($post['audience'] === 'all') return ["SELECT id FROM employees WHERE is_active = 1", []];
    $stmt = $pdo->prepare("SELECT org_unit_id FROM news_post_units WHERE post_id = ?");
    $stmt->execute([$post['id']]);
    $units = newsUnitDescendants($pdo, $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (!$units) return ["SELECT id FROM employees WHERE 1 = 0", []];
    return ["SELECT id FROM employees WHERE is_active = 1 AND org_unit_id IN (" . implode(',', array_fill(0, count($units), '?')) . ")", $units];
}

/**
 * Нийтлэгдсэн бөгөөд цаг нь болсон, мэдэгдэл хараахан явуулаагүй нийтлэлүүдэд 🔔 мэдэгдэл (+ сонгосон бол имэйл) илгээнэ.
 * Хуваарьт нийтлэлийг cron-гүйгээр барихын тулд мэдэгдэл шалгах хүсэлт бүрт дуудагдана.
 * notified_at-ыг атомаар "эзэмшиж" авдаг тул хэд хэдэн хүсэлт зэрэг ирсэн ч нэг л удаа илгээнэ.
 */
function newsDispatchDue(PDO $pdo): void {
    $due = $pdo->query("SELECT id, author_id, title, body, audience, notify_email FROM news_posts
                        WHERE status = 'published' AND published_at <= NOW() AND notified_at IS NULL LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($due as $post) {
        $claim = $pdo->prepare("UPDATE news_posts SET notified_at = NOW() WHERE id = ? AND notified_at IS NULL");
        $claim->execute([$post['id']]);
        if ($claim->rowCount() !== 1) continue;

        $link = '/modules/news/post.php?id=' . (int)$post['id'];
        [$sql, $params] = newsAudienceSql($pdo, $post);
        notifyBySql($pdo, $sql, $params, 'news.post', 'Шинэ мэдээ: ' . $post['title'], newsExcerpt($post['body'], 140), $link, (int)$post['author_id']);

        if ($post['notify_email'] && $post['audience'] === 'all' && newsMailListReady()) {
            try {
                $url = rtrim(APP_PUBLIC_URL, '/') . $link;
                $excerpt = newsExcerpt($post['body'], 400);
                $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#1c2430;line-height:1.6">'
                      . '<h2 style="margin:0 0 12px;font-size:18px">' . htmlspecialchars($post['title']) . '</h2>'
                      . '<p>' . htmlspecialchars($excerpt) . '</p>'
                      . '<p><a href="' . htmlspecialchars($url) . '" style="display:inline-block;background:#f1592a;color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:bold">Бүтнээр нь унших</a></p>'
                      . '<p style="color:#64748b;font-size:12px">Оффисын сүлжээнээс нээнэ үү.</p></div>';
                sendMail(MAIL_ALL_EMPLOYEES, '', $post['title'], $html, $post['title'] . "\n\n" . $excerpt . "\n\n" . $url);
            } catch (Exception $e) {
                error_log('[news email] post ' . $post['id'] . ': ' . $e->getMessage());
            }
        }
    }
}

/** Нэгдсэн имэйл хаяг тохируулагдсан эсэх */
function newsMailListReady(): bool {
    return mailConfigured() && defined('MAIL_ALL_EMPLOYEES') && filter_var(MAIL_ALL_EMPLOYEES, FILTER_VALIDATE_EMAIL);
}

/** HTML-ээс товч текст */
function newsExcerpt(?string $html, int $len = 200): string {
    $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '</li>'], ' ', (string)$html)), ENT_QUOTES, 'UTF-8')));
    return mb_strlen($text) > $len ? rtrim(mb_substr($text, 0, $len)) . '…' : $text;
}

/**
 * Quill-ийн HTML-ийг цэвэрлэнэ: зөвшөөрөгдсөн таг үлдэнэ, бусдыг нь агуулгаар нь солино,
 * бүх атрибутыг арилгана (зөвхөн <a href>-ийг http/https/mailto үед үлдээнэ).
 */
function newsSanitizeHtml(?string $html): string {
    $html = trim((string)$html);
    if ($html === '') return '';
    $allowed = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'ul', 'ol', 'li', 'h2', 'h3', 'blockquote', 'a'];
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);
    libxml_clear_errors();
    $root = $doc->getElementsByTagName('div')->item(0);
    if (!$root) return '';

    $walk = function (DOMNode $node) use (&$walk, $doc, $allowed) {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMText) continue;
            if (!($child instanceof DOMElement)) { $node->removeChild($child); continue; }
            $walk($child);
            $tag = strtolower($child->nodeName);
            if (in_array($tag, ['script', 'style', 'iframe', 'object'], true)) { $node->removeChild($child); continue; }
            if (!in_array($tag, $allowed, true)) {
                while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                $node->removeChild($child);
                continue;
            }
            $href = $tag === 'a' ? trim($child->getAttribute('href')) : '';
            foreach (iterator_to_array($child->attributes) as $attr) $child->removeAttribute($attr->nodeName);
            if ($tag === 'a') {
                if (preg_match('#^(https?://|mailto:)#i', $href)) {
                    $child->setAttribute('href', $href);
                    $child->setAttribute('target', '_blank');
                    $child->setAttribute('rel', 'noopener noreferrer');
                }
            }
        }
    };
    $walk($root);
    $out = '';
    foreach (iterator_to_array($root->childNodes) as $child) $out .= $doc->saveHTML($child);
    return trim($out);
}

/** Нүүр зураг хадгална, файлын нэрийг буцаана (байхгүй бол null) */
function newsUploadCover(): ?string {
    if (empty($_FILES['cover']['name'])) return null;
    $file = $_FILES['cover'];
    if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception('Зураг хуулахад алдаа гарлаа.');
    if ($file['size'] > NEWS_MAX_FILE_SIZE) throw new Exception('Зураг 5MB-аас хэтэрч байна.');
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || !@getimagesize($file['tmp_name'])) {
        throw new Exception('Зөвхөн JPG, PNG, WEBP зураг оруулна уу.');
    }
    if (!is_dir(NEWS_UPLOAD_PATH)) mkdir(NEWS_UPLOAD_PATH, 0755, true);
    $name = 'news_' . bin2hex(random_bytes(10)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], NEWS_UPLOAD_PATH . $name)) throw new Exception('Зураг хадгалахад алдаа гарлаа.');
    return $name;
}

/** Нийтлэлийг ачаална (байхгүй бол Exception) */
function newsLoadPost(PDO $pdo, int $id): array {
    $stmt = $pdo->prepare("SELECT * FROM news_posts WHERE id = ?");
    $stmt->execute([$id]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$post) throw new Exception('Нийтлэл олдсонгүй.');
    return $post;
}

/** Тухайн ажилтанд нийтлэл харагдах эсэх */
function newsCanView(PDO $pdo, int $postId, int $employeeId, ?int $orgUnitId): bool {
    [$where, $params] = newsVisibilityWhere($pdo, $employeeId, $orgUnitId);
    $stmt = $pdo->prepare("SELECT 1 FROM news_posts p WHERE p.id = ? AND ($where)");
    $stmt->execute(array_merge([$postId], $params));
    return (bool)$stmt->fetchColumn() || isSuperAdmin();
}

/** Нийтлэлийг засах эрх — зохиогч эсвэл superadmin */
function newsCanEdit(array $post, int $employeeId): bool {
    return isSuperAdmin() || ((int)$post['author_id'] === $employeeId && newsCanPublish());
}

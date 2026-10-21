<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/auth.php';
require_once __DIR__ . '/functions.php';
header('Content-Type: application/json');
requireLogin();

$pdo   = getDB();
$me    = (int)$_SESSION['user_id'];
$myOrg = isset($_SESSION['user_org_unit_id']) ? (int)$_SESSION['user_org_unit_id'] : null;
$action = $_POST['action'] ?? '';

/** Харах эрхтэй нийтлэлийг ачаална */
function loadVisiblePost(PDO $pdo, int $id, int $me, ?int $myOrg): array {
    $post = newsLoadPost($pdo, $id);
    if (!newsCanView($pdo, $id, $me, $myOrg)) throw new Exception('Энэ нийтлэлийг харах эрхгүй байна.');
    return $post;
}

try {
    switch ($action) {
        // ─── Нийтлэл хадгалах (шинэ / засах) ───────────────────────
        case 'save_post':
            if (!newsCanPublish()) throw new Exception('Нийтлэх эрхгүй байна.');
            $id = intval($_POST['id'] ?? 0);
            $existing = $id ? newsLoadPost($pdo, $id) : null;
            if ($existing && !newsCanEdit($existing, $me)) throw new Exception('Энэ нийтлэлийг засах эрхгүй байна.');

            $title = trim($_POST['title'] ?? '');
            $category = $_POST['category'] ?? '';
            $body = newsSanitizeHtml($_POST['body'] ?? '');
            $audience = ($_POST['audience'] ?? 'all') === 'units' ? 'units' : 'all';
            $unitIds = array_values(array_unique(array_filter(array_map('intval', json_decode($_POST['unit_ids'] ?? '[]', true) ?: []))));
            $status = ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
            $publishAt = trim($_POST['published_at'] ?? '');

            if ($title === '' || mb_strlen($title) > 255) throw new Exception('Гарчгаа оруулна уу (255 тэмдэгтээс ихгүй).');
            if (!isset(NEWS_CATEGORIES[$category])) throw new Exception('Ангиллаа сонгоно уу.');
            if (trim(strip_tags($body)) === '') throw new Exception('Нийтлэлийн агуулгаа бичнэ үү.');
            if ($audience === 'units' && !$unitIds) throw new Exception('Хамаарах нэгжээ сонгоно уу.');

            $publishedAt = null;
            if ($status === 'published') {
                if ($publishAt !== '') {
                    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $publishAt);
                    if (!$dt) throw new Exception('Нийтлэх огноо буруу байна.');
                    $publishedAt = $dt->format('Y-m-d H:i:s');
                } else {
                    // Аль хэдийн нийтлэгдсэн бол анхны огноог хадгална
                    $publishedAt = ($existing && $existing['published_at'] && $existing['status'] === 'published') ? $existing['published_at'] : date('Y-m-d H:i:s');
                }
            }
            $notifyEmail = ($audience === 'all' && !empty($_POST['notify_email']) && newsMailListReady()) ? 1 : 0;

            $pdo->beginTransaction();
            $cover = newsUploadCover();
            $oldCover = $existing['cover_image'] ?? null;
            if (!$cover && !empty($_POST['remove_cover'])) $cover = '';
            $fields = [$title, $category, $body, $audience, !empty($_POST['is_pinned']) ? 1 : 0, !empty($_POST['allow_comments']) ? 1 : 0, $notifyEmail, $status, $publishedAt];
            if ($existing) {
                $sql = "UPDATE news_posts SET title=?, category=?, body=?, audience=?, is_pinned=?, allow_comments=?, notify_email=?, status=?, published_at=?"
                     . ($cover !== null ? ", cover_image=?" : "") . " WHERE id=?";
                $params = $cover !== null ? array_merge($fields, [$cover ?: null, $id]) : array_merge($fields, [$id]);
                $pdo->prepare($sql)->execute($params);
            } else {
                $pdo->prepare("INSERT INTO news_posts (title, category, body, audience, is_pinned, allow_comments, notify_email, status, published_at, cover_image, author_id)
                               VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute(array_merge($fields, [$cover ?: null, $me]));
                $id = (int)$pdo->lastInsertId();
            }
            $pdo->prepare("DELETE FROM news_post_units WHERE post_id=?")->execute([$id]);
            if ($audience === 'units') {
                $insU = $pdo->prepare("INSERT INTO news_post_units (post_id, org_unit_id) VALUES (?,?)");
                foreach ($unitIds as $u) $insU->execute([$id, $u]);
            }
            $pdo->commit();
            if ($cover !== null && $oldCover && $oldCover !== $cover && is_file(NEWS_UPLOAD_PATH . $oldCover)) @unlink(NEWS_UPLOAD_PATH . $oldCover);

            // Одоо нийтлэгдсэн бол мэдэгдлийг шууд илгээнэ (хуваарьтыг цаг нь болмогц хонх шалгахад илгээнэ)
            try { newsDispatchDue($pdo); } catch (Throwable $e) { error_log('[news dispatch] ' . $e->getMessage()); }

            $scheduled = $status === 'published' && strtotime($publishedAt) > time();
            echo json_encode(['success' => true, 'id' => $id, 'message' => $status === 'draft' ? 'Ноорог хадгалагдлаа.' : ($scheduled ? 'Хуваарьт нийтлэл хадгалагдлаа.' : 'Нийтлэгдлээ.')]);
            break;

        case 'delete_post':
            $post = newsLoadPost($pdo, intval($_POST['id'] ?? 0));
            if (!newsCanEdit($post, $me)) throw new Exception('Устгах эрхгүй байна.');
            $pdo->prepare("DELETE FROM news_posts WHERE id=?")->execute([$post['id']]);
            $pdo->prepare("DELETE FROM notifications WHERE link = ?")->execute(['/modules/news/post.php?id=' . (int)$post['id']]);
            if ($post['cover_image'] && is_file(NEWS_UPLOAD_PATH . $post['cover_image'])) @unlink(NEWS_UPLOAD_PATH . $post['cover_image']);
            echo json_encode(['success' => true, 'message' => 'Нийтлэл устгагдлаа.']);
            break;

        // ─── Like ───────────────────────────────────────────────
        case 'toggle_like':
            $post = loadVisiblePost($pdo, intval($_POST['post_id'] ?? 0), $me, $myOrg);
            $del = $pdo->prepare("DELETE FROM news_likes WHERE post_id=? AND employee_id=?");
            $del->execute([$post['id'], $me]);
            $liked = false;
            if ($del->rowCount() === 0) {
                $pdo->prepare("INSERT IGNORE INTO news_likes (post_id, employee_id) VALUES (?,?)")->execute([$post['id'], $me]);
                $liked = true;
            }
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM news_likes WHERE post_id=?");
            $cnt->execute([$post['id']]);
            echo json_encode(['success' => true, 'liked' => $liked, 'count' => (int)$cnt->fetchColumn()]);
            break;

        // ─── Сэтгэгдэл ─────────────────────────────────────────
        case 'add_comment':
            $post = loadVisiblePost($pdo, intval($_POST['post_id'] ?? 0), $me, $myOrg);
            if (!$post['allow_comments']) throw new Exception('Энэ нийтлэлд сэтгэгдэл бичих боломжгүй.');
            $body = trim($_POST['body'] ?? '');
            if ($body === '') throw new Exception('Сэтгэгдлээ бичнэ үү.');
            if (mb_strlen($body) > 2000) throw new Exception('Сэтгэгдэл 2000 тэмдэгтээс ихгүй байна.');

            $parentId = intval($_POST['parent_id'] ?? 0) ?: null;
            $parentAuthor = null;
            if ($parentId) {
                $p = $pdo->prepare("SELECT id, parent_id, employee_id FROM news_comments WHERE id=? AND post_id=?");
                $p->execute([$parentId, $post['id']]);
                $parent = $p->fetch(PDO::FETCH_ASSOC);
                if (!$parent) throw new Exception('Хариулах сэтгэгдэл олдсонгүй.');
                $parentAuthor = (int)$parent['employee_id'];
                if ($parent['parent_id']) $parentId = (int)$parent['parent_id']; // нэг түвшин — хариултын хариулт эх сэтгэгдэлд очно
            }
            $pdo->prepare("INSERT INTO news_comments (post_id, employee_id, parent_id, body) VALUES (?,?,?,?)")
                ->execute([$post['id'], $me, $parentId, $body]);
            $commentId = (int)$pdo->lastInsertId();

            $link = '/modules/news/post.php?id=' . (int)$post['id'] . '#c' . $commentId;
            $snippet = mb_strimwidth($body, 0, 140, '…');
            if ($parentAuthor) notifyEmployees($pdo, [$parentAuthor], 'news.reply', 'Таны сэтгэгдэлд хариуллаа: ' . $post['title'], $snippet, $link, $me);
            if ((int)$post['author_id'] !== $parentAuthor) {
                notifyEmployees($pdo, [(int)$post['author_id']], 'news.comment', 'Таны нийтлэлд сэтгэгдэл бичлээ: ' . $post['title'], $snippet, $link, $me);
            }
            echo json_encode(['success' => true, 'id' => $commentId]);
            break;

        case 'edit_comment':
            $c = $pdo->prepare("SELECT * FROM news_comments WHERE id=? AND deleted_at IS NULL");
            $c->execute([intval($_POST['id'] ?? 0)]);
            $comment = $c->fetch(PDO::FETCH_ASSOC);
            if (!$comment || (int)$comment['employee_id'] !== $me) throw new Exception('Зөвхөн өөрийн сэтгэгдлийг засна.');
            $body = trim($_POST['body'] ?? '');
            if ($body === '' || mb_strlen($body) > 2000) throw new Exception('Сэтгэгдэл хоосон эсвэл хэт урт байна.');
            $pdo->prepare("UPDATE news_comments SET body=?, updated_at=NOW() WHERE id=?")->execute([$body, $comment['id']]);
            echo json_encode(['success' => true]);
            break;

        case 'delete_comment':
            $c = $pdo->prepare("SELECT c.*, p.author_id AS post_author FROM news_comments c JOIN news_posts p ON p.id=c.post_id WHERE c.id=? AND c.deleted_at IS NULL");
            $c->execute([intval($_POST['id'] ?? 0)]);
            $comment = $c->fetch(PDO::FETCH_ASSOC);
            if (!$comment) throw new Exception('Сэтгэгдэл олдсонгүй.');
            if ((int)$comment['employee_id'] !== $me && (int)$comment['post_author'] !== $me && !isSuperAdmin()) throw new Exception('Устгах эрхгүй байна.');
            $pdo->prepare("UPDATE news_comments SET deleted_at=NOW(), body='' WHERE id=?")->execute([$comment['id']]);
            echo json_encode(['success' => true]);
            break;

        // ─── Нийтлэгч томилох (зөвхөн superadmin) ─────────────────
        case 'grant_publisher':
        case 'revoke_publisher':
            if (!isSuperAdmin()) throw new Exception('Зөвхөн superadmin.');
            $u = $pdo->prepare("SELECT id FROM users WHERE employee_id=?");
            $u->execute([intval($_POST['employee_id'] ?? 0)]);
            $userId = $u->fetchColumn();
            if (!$userId) throw new Exception('Энэ ажилтан нэвтрэх эрхгүй байна.');
            $permId = $pdo->query("SELECT id FROM permissions WHERE code='news.publish'")->fetchColumn();
            if ($action === 'grant_publisher') {
                $pdo->prepare("INSERT IGNORE INTO user_permissions (user_id, permission_id, effect) VALUES (?,?,'allow')")->execute([$userId, $permId]);
            } else {
                $pdo->prepare("DELETE FROM user_permissions WHERE user_id=? AND permission_id=?")->execute([$userId, $permId]);
            }
            echo json_encode(['success' => true, 'message' => $action === 'grant_publisher' ? 'Нийтлэх эрх олголоо.' : 'Эрхийг цуцаллаа.']);
            break;

        default:
            throw new Exception('Тодорхойгүй үйлдэл.');
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

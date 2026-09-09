<?php
if(session_status() === PHP_SESSION_NONE) session_start();

// ─── Нэвтрэлт ────────────────────────────────────────────
function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function requireLogin(): void {
    if(!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function requireRole(string ...$roles): void {
    requireLogin();
    if(!array_intersect($_SESSION['user_roles'] ?? [], $roles)) {
        header('Location: ' . BASE_URL . '/denied.php');
        exit;
    }
}

// ─── Эрхийн helper функцүүд ──────────────────────────────
//
//  Roles (users дээр олон role зэрэг байж болно — user_roles хүснэгт):
//    superadmin — Супер Админ      (бүх эрх)
//    hr         — HR Менежер       (бүтэц + KPI бүгд хянах)
//    director   — Газрын захирал   (SIM батлах, org_unit удирдах)
//    manager    — Хэлтсийн захирал (SIM үүсгэх, ажилтны KPI батлах)
//    employee   — Ажилтан          (зөвхөн өөрийн Action Plan)
//
//  Дээр нь user_permissions хүснэгтээр ажилтан тус бүрт role-оос үл
//  хамааран эрх нэмж/хасаж болно (dynamic) — үүнийг hasPermission()-ээр шалгана.

/** Хэрэглэгч тухайн role-той эсэхийг шалгах */
function hasRole(string $role): bool {
    return in_array($role, $_SESSION['user_roles'] ?? [], true);
}

/** superadmin */
function isSuperAdmin(): bool {
    return hasRole('superadmin');
}

/** hr + superadmin */
function isHR(): bool {
    return hasRole('hr') || hasRole('superadmin');
}

/** director + hr + superadmin */
function isDirector(): bool {
    return hasRole('director') || hasRole('hr') || hasRole('superadmin');
}

/** manager (хэлтсийн захирал) — зөвхөн manager role */
function isManager(): bool {
    return hasRole('manager');
}

/**
 * Тодорхой permission code (жишээ: 'employees.edit') олгогдсон эсэхийг шалгах.
 * Дараалал: user_permissions дахь deny > allow > role-оос ирсэн эрх > default false.
 */
function hasPermission(string $code): bool {
    if (isSuperAdmin()) return true;

    $authUserId = $_SESSION['auth_user_id'] ?? null;
    if (!$authUserId) return false;

    $pdo = getDB();

    $stmt = $pdo->prepare("SELECT effect FROM user_permissions up
                            JOIN permissions p ON p.id = up.permission_id
                            WHERE up.user_id = ? AND p.code = ?
                              AND (up.expires_at IS NULL OR up.expires_at > NOW())");
    $stmt->execute([$authUserId, $code]);
    $override = $stmt->fetchColumn();
    if ($override === 'deny') return false;
    if ($override === 'allow') return true;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_roles ur
                            JOIN role_permissions rp ON rp.role_id = ur.role_id
                            JOIN permissions p ON p.id = rp.permission_id
                            WHERE ur.user_id = ? AND p.code = ?");
    $stmt->execute([$authUserId, $code]);
    return (bool) $stmt->fetchColumn();
}

// ─── Одоогийн хэрэглэгч ──────────────────────────────────
function currentUser(): array {
    $roles = $_SESSION['user_roles'] ?? [];
    return [
        'id'          => $_SESSION['user_id']          ?? null,
        'auth_id'     => $_SESSION['auth_user_id']      ?? null,
        'name'        => $_SESSION['user_name']        ?? '',
        'role'        => $roles[0] ?? ($_SESSION['user_role'] ?? 'employee'),
        'roles'       => $roles,
        'code'        => $_SESSION['user_code']        ?? '',
        'org_unit_id' => $_SESSION['user_org_unit_id'] ?? null,
    ];
}

// ─── Role гарчиг ─────────────────────────────────────────
function roleLabel(string $role): string {
    return [
        'superadmin' => 'Супер Админ',
        'hr'         => 'HR Менежер',
        'director'   => 'Газрын захирал',
        'manager'    => 'Хэлтсийн захирал',
        'employee'   => 'Ажилтан',
    ][$role] ?? $role;
}

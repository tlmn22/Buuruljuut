<?php
/** Нууц үгийн бодлого болон туслах функцууд (солих / сэргээх / admin reset хамтдаа ашиглана) */

const PASSWORD_MIN_LENGTH = 8;
const PASSWORD_RESET_TTL_MINUTES = 30;
const PASSWORD_RESET_MAX_PER_HOUR = 3;

/** Бодлогод тохирохгүй бол алдааны текст, тохирвол null */
function passwordPolicyError(string $password): ?string {
    if (mb_strlen($password) < PASSWORD_MIN_LENGTH) return 'Нууц үг хамгийн багадаа ' . PASSWORD_MIN_LENGTH . ' тэмдэгт байна.';
    if (!preg_match('/\pL/u', $password) || !preg_match('/\d/', $password)) return 'Нууц үг дор хаяж нэг үсэг, нэг тоо агуулна.';
    return null;
}

/** Хүнд уншиж дамжуулахад амар түр нууц үг (андуурагддаг 0/O, 1/l/I тэмдэгтгүй) */
function generateTempPassword(int $length = 10): string {
    $letters = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
    $digits  = '23456789';
    $all = $letters . $digits;
    $chars = [$letters[random_int(0, strlen($letters) - 1)], $digits[random_int(0, strlen($digits) - 1)]];
    while (count($chars) < $length) $chars[] = $all[random_int(0, strlen($all) - 1)];
    shuffle($chars);
    return implode('', $chars);
}

/**
 * Нууц үг тохируулна. $mustChange = true бол дараагийн нэвтрэлтэд солиулна (admin-ы өгсөн түр нууц үг).
 * Хүлээгдэж буй бүх сэргээх холбоосыг хүчингүй болгоно.
 */
function setUserPassword(PDO $pdo, int $authUserId, string $password, bool $mustChange): void {
    $pdo->prepare("UPDATE users SET password_hash=?, must_change_password=?, password_changed_at=NOW() WHERE id=?")
        ->execute([password_hash($password, PASSWORD_DEFAULT), $mustChange ? 1 : 0, $authUserId]);
    $pdo->prepare("UPDATE password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL")->execute([$authUserId]);
}

<?php
namespace uii\portal\domain;

/** Правила RP-процессов; часы всегда передаются сервером. */
final class workflow
{
    public static function requestState(int $deadline, int $answered, bool $closed, int $now): string
    {
        if ($closed) { return 'closed'; }
        if ($answered > 0) { return $answered <= $deadline ? 'answered_on_time' : 'answered_late'; }
        return $now >= $deadline ? 'overdue' : 'waiting';
    }
    public static function deadline(int $started): int
    {
        if ($started <= 0) { throw new \InvalidArgumentException('Не задано начало срока.'); }
        return $started + 48 * 3600;
    }
    public static function url(string $value): string
    {
        $value = trim($value);
        if (!filter_var($value, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Укажите полную ссылку http или https.');
        }
        if (parse_url($value, PHP_URL_USER) !== null || parse_url($value, PHP_URL_PASS) !== null) {
            throw new \InvalidArgumentException('Ссылки с логином и паролем запрещены.');
        }
        return $value;
    }
    public static function closeComplaint(array $data): void
    {
        foreach (['protocol', 'document_url', 'decision', 'violations'] as $field) {
            if (!self::hasMaterial($data, $field)) {
                throw new \InvalidArgumentException('Для закрытия прикрепите фото протокола жалобы и Фото ДО, заполните решение и сведения о нарушениях (либо укажите, что они не установлены).');
            }
        }
        if (!empty($data['document_url'])) { self::url($data['document_url']); }
    }
    public static function hasMaterial(array $data, string $field): bool
    {
        if (trim((string) ($data[$field] ?? '')) !== '') { return true; }
        foreach (($data['photos'] ?? []) as $photo) {
            if (($photo['slot'] ?? '') === $field && preg_match('/^[a-f0-9]{32}$/D', $photo['token'] ?? '')) { return true; }
        }
        return false;
    }
    public static function closeInspection(array $data, int $started): void
    {
        foreach (['start_url','end_url','decision','violations'] as $field) {
            if (!self::hasMaterial($data,$field)) { throw new \InvalidArgumentException('Для завершения добавьте фото или ссылки начала и окончания, результат и сведения о нарушениях.'); }
        }
        if (!$started) { throw new \InvalidArgumentException('Сначала начните проверку.'); }
    }
    /** Only finalized, evidenced answers with a coherent recorded timeline get an outcome. */
    public static function responseOutcome(array $row, array $data, int $now): string
    {
        $start=(int)($row['started_at'] ?? 0);$deadline=(int)($row['deadline_at'] ?? 0);
        $answer=(int)($row['answered_at'] ?? 0);$closed=(int)($row['closed_at'] ?? 0);
        if (!$closed || $start<=0 || $deadline<=$start || $answer<$start || $answer>$closed || $closed>$now || empty($data['answer_url'])) { return ''; }
        try { self::url($data['answer_url']); } catch (\InvalidArgumentException $exception) { return ''; }
        return $answer<=$deadline ? 'answered_on_time' : 'answered_late';
    }
    public static function nickname(string $value): bool
    {
        return (bool) preg_match('/^[A-Z][a-z]{1,19}_[A-Z][a-z]{1,19}$/D', $value);
    }
}

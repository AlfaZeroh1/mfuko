<?php
declare(strict_types=1);

/** Append-only audit trail. Call on every state-changing action. */
final class Audit
{
    public static function log(
        string $action,
        ?string $entity = null,
        $entityId = null,
        ?string $summary = null,
        ?array $old = null,
        ?array $new = null,
        ?int $userId = null
    ): void {
        try {
            $strip = static function (?array $a): ?string {
                if ($a === null) return null;
                foreach (['password_hash', 'pin_hash', 'password', 'pin', 'smtp_pass', 'ai_api_key', 'mpesa_passkey', 'mpesa_consumer_secret', 'sms_api_key'] as $k) {
                    if (array_key_exists($k, $a)) $a[$k] = '***';
                }
                return json_encode($a, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            };
            // Only keep changed keys when both sides are given.
            if ($old !== null && $new !== null) {
                $changedOld = $changedNew = [];
                foreach ($new as $k => $v) {
                    if (!array_key_exists($k, $old) || (string)$old[$k] !== (string)$v) {
                        $changedOld[$k] = $old[$k] ?? null;
                        $changedNew[$k] = $v;
                    }
                }
                $old = $changedOld; $new = $changedNew;
            }
            DB::insert('audit_logs', [
                'user_id'    => $userId ?? (Auth::id() ?: null),
                'action'     => substr($action, 0, 48),
                'entity'     => $entity,
                'entity_id'  => $entityId === null ? null : (string)$entityId,
                'summary'    => $summary ? mb_substr($summary, 0, 255) : null,
                'old_values' => $strip($old),
                'new_values' => $strip($new),
                'ip'         => MF_CLI ? 'cli' : client_ip(),
                'user_agent' => MF_CLI ? 'cli' : mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        } catch (Throwable $e) {
            error_log('Audit failed: ' . $e->getMessage());
        }
    }
}

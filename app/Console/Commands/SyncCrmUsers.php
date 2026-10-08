<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PDOException;
use RuntimeException;
use Throwable;

#[Signature('crm:sync-users {--dry-run : Проверить синхронизацию без сохранения изменений}')]
#[Description('Синхронизировать пользователей SugarCRM с локальной базой чата')]
class SyncCrmUsers extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $local = (new User)->getConnection();
        $transactionLevel = $local->transactionLevel();

        try {
            $crm = DB::connection('crm');

            if (! $crm->getDatabaseName()) {
                throw new RuntimeException('Укажите CRM_DB_DATABASE и остальные параметры подключения к CRM.');
            }

            if ($local->getName() === $crm->getName()
                || ($local->getDriverName() === $crm->getDriverName()
                    && $local->getDatabaseName() === $crm->getDatabaseName()
                    && $local->getConfig('host') === $crm->getConfig('host')
                    && $local->getConfig('port') === $crm->getConfig('port'))) {
                throw new RuntimeException('База CRM и локальная база чата должны быть разными.');
            }

            $this->info('Подключение к CRM и чтение структуры users...');
            $columns = $crm->getSchemaBuilder()->getColumnListing('users');
            $requiredColumns = ['id', 'user_name', 'user_hash', 'first_name', 'last_name', 'status', 'deleted'];

            if (array_diff($requiredColumns, $columns) !== []) {
                throw new RuntimeException('В таблице users CRM отсутствуют обязательные поля SugarCRM.');
            }

            $excludedFlags = array_values(array_intersect(['is_group', 'portal_only', 'external_auth_only'], $columns));
            $sourceUsers = $crm->table('users')
                ->select(['id', 'user_name', 'user_hash', 'first_name', 'last_name', 'status'])
                ->selectRaw('deleted + 0 as deleted');

            foreach ($excludedFlags as $flag) {
                $sourceUsers->selectRaw($flag.' + 0 as '.$flag);
            }

            $this->info('Подключение к базе чата и начало транзакции...');
            $local->beginTransaction();
            $this->info('Подготовка локальных пользователей к синхронизации...');
            User::query()->whereNotNull('crm_id')->update(['crm_active' => false]);
            $synchronized = 0;
            $seen = 0;
            $created = 0;

            $this->info('Чтение сотрудников из CRM...');

            foreach ($sourceUsers->lazyById(250) as $sourceUser) {
                $seen++;
                $this->line("Обработка записи №{$seen}: поиск локального пользователя...", verbosity: 'v');
                $crmId = (string) $sourceUser->id;
                $username = Str::lower(trim((string) $sourceUser->user_name));

                if ($crmId === '' || strlen($crmId) > 36 || $username === '' || mb_strlen($username) > 255) {
                    throw new RuntimeException('В CRM обнаружен пользователь с некорректным ID или логином. Изменения отменены.');
                }

                $active = (int) $sourceUser->deleted === 0
                    && strcasecmp((string) $sourceUser->status, 'Active') === 0;

                foreach ($excludedFlags as $flag) {
                    $active = $active && (int) $sourceUser->{$flag} === 0;
                }

                $passwordHash = (string) ($sourceUser->user_hash ?? '');

                if ($active && preg_match('/\A[0-9a-f]{32}\z/i', $passwordHash) !== 1) {
                    throw new RuntimeException('У активного пользователя CRM обнаружен неподдерживаемый хеш пароля. Ожидается MD5 из 32 символов. Изменения отменены.');
                }

                $user = User::query()->where('crm_id', $crmId)->first() ?? new User;

                if (! $user->exists && ! $active) {
                    continue;
                }

                $passwordChanged = $user->exists && $user->crm_password_hash !== $passwordHash;

                if (! $user->exists) {
                    $this->line("Обработка записи №{$seen}: подготовка новой учётной записи...", verbosity: 'v');
                    $user->password = Hash::make(Str::random(64));
                    $created++;
                }

                $user->forceFill([
                    'crm_id' => $crmId,
                    'name' => trim((string) $sourceUser->first_name.' '.(string) $sourceUser->last_name) ?: $username,
                    'crm_username' => $username,
                    'crm_password_hash' => $passwordHash,
                    'crm_active' => $active,
                    'crm_synced_at' => now(),
                ]);

                if ($passwordChanged || ! $active) {
                    $user->remember_token = null;
                }

                $this->line("Обработка записи №{$seen}: сохранение в базу чата...", verbosity: 'v');
                $user->save();

                if ($passwordChanged) {
                    $this->revokeDatabaseSessions([$user->id]);
                }

                $synchronized++;

                if ($synchronized === 1 || $synchronized % 25 === 0) {
                    $this->info("Обработано пользователей: {$synchronized}. Новых: {$created}.");
                }
            }

            if ($seen === 0 && User::query()->whereNotNull('crm_id')->exists()) {
                throw new RuntimeException('Таблица пользователей CRM оказалась пустой. Изменения отменены.');
            }

            $this->info('Обновление доступа отключённых сотрудников...');
            User::query()->whereNotNull('crm_id')->where('crm_active', false)
                ->chunkById(250, function (Collection $users): void {
                    User::query()->whereIn('id', $users->modelKeys())->update(['remember_token' => null]);
                    $this->revokeDatabaseSessions($users->modelKeys());
                });

            if ($this->option('dry-run')) {
                $local->rollBack();
                $this->info("Проверка завершена: {$synchronized} пользователей. Изменения не сохранены.");
            } else {
                $local->commit();
                $this->info("Синхронизировано пользователей: {$synchronized}. Добавлено новых: {$created}.");
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($local->transactionLevel() > $transactionLevel) {
                $local->rollBack($transactionLevel);
            }

            $context = ['exception_type' => $exception::class];

            if ($exception instanceof PDOException) {
                $connection = $exception instanceof QueryException ? $exception->getConnectionName() : 'unknown';
                $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
                $driverCode = (int) ($exception->errorInfo[1] ?? 0);
                $reason = match ($driverCode) {
                    1045 => 'MySQL отклонил вход: проверьте пользователя, пароль и разрешённый адрес сервера чата.',
                    1044, 1142, 1143 => 'Недостаточно прав доступа к базе или таблице. Для CRM требуется SELECT на таблицу users.',
                    1049 => 'Указанная база данных не существует. Проверьте название базы.',
                    1146 => 'Таблица не существует. Проверьте таблицу users в CRM и миграции базы чата.',
                    1054 => 'Отсутствует нужный столбец. Проверьте миграции базы чата и структуру таблицы CRM.',
                    19, 1062 => 'Нарушено ограничение таблицы. Проверьте повторяющиеся логины и обязательные поля.',
                    2002, 2003, 2005 => 'Не удалось подключиться к MySQL. Проверьте адрес, порт и доступность сервера.',
                    default => 'Ошибка базы данных. Для диагностики используйте указанные SQLSTATE и код драйвера.',
                };

                $this->error("Синхронизация не выполнена. Подключение: {$connection}; SQLSTATE: {$sqlState}; код: {$driverCode}. {$reason} Изменения отменены.");
                $context += ['connection' => $connection, 'sqlstate' => $sqlState, 'driver_code' => $driverCode, 'reason' => $reason];
            } elseif ($exception instanceof RuntimeException) {
                $this->error($exception->getMessage());
            } else {
                $this->error('Синхронизация не выполнена. Проверьте подключение к CRM и ограничения локальной таблицы users. Изменения отменены.');
            }

            Log::error('CRM user synchronization failed.', $context);

            return self::FAILURE;
        }
    }

    /** @param array<int, int> $userIds */
    private function revokeDatabaseSessions(array $userIds): void
    {
        if (config('session.driver') !== 'database'
            || (config('session.connection') !== null && config('session.connection') !== config('database.default'))) {
            return;
        }

        DB::table(config('session.table', 'sessions'))->whereIn('user_id', $userIds)->delete();
    }
}

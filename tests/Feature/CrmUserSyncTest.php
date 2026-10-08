<?php

use App\Models\Chat;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

/** @param array<int, array<string, mixed>> $users */
function seedCrmUsers(array $users): Connection
{
    $database = tempnam(sys_get_temp_dir(), 'crm-users-test-');
    config(['database.connections.crm' => [
        'driver' => 'sqlite',
        'database' => $database,
        'prefix' => '',
    ]]);
    DB::purge('crm');
    $connection = DB::connection('crm');
    $connection->getSchemaBuilder()->create('users', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('user_name');
        $table->string('user_hash')->nullable();
        $table->string('first_name')->nullable();
        $table->string('last_name')->nullable();
        $table->string('status');
        $table->boolean('deleted')->default(false);
        $table->boolean('is_group')->default(false);
        $table->boolean('portal_only')->default(false);
        $table->boolean('external_auth_only')->default(false);
    });

    foreach ($users as $user) {
        $connection->table('users')->insert(array_replace([
            'id' => 'crm-ivan',
            'user_name' => 'Ivan',
            'user_hash' => md5('crm-password'),
            'first_name' => 'Иван',
            'last_name' => 'Петров',
            'status' => 'Active',
            'deleted' => false,
            'is_group' => false,
            'portal_only' => false,
            'external_auth_only' => false,
        ], $user));
    }

    return $connection;
}

afterEach(function () {
    $database = config('database.connections.crm.database');
    DB::purge('crm');

    if (is_string($database) && str_starts_with($database, sys_get_temp_dir().'/crm-users-test-')) {
        unlink($database);
    }
});

test('synchronization imports CRM users using a read only source connection', function () {
    $localUser = User::factory()->create();
    $crm = seedCrmUsers([[]]);
    $crm->statement('PRAGMA query_only = ON');

    $this->artisan('crm:sync-users')->assertSuccessful();

    $this->assertDatabaseHas('users', [
        'crm_id' => 'crm-ivan',
        'crm_username' => 'ivan',
        'name' => 'Иван Петров',
        'email' => null,
        'crm_password_hash' => md5('crm-password'),
        'crm_active' => true,
    ]);
    $this->assertDatabaseHas('users', [
        'id' => $localUser->id,
        'email' => $localUser->email,
        'password' => $localUser->password,
        'crm_id' => null,
    ]);
    $this->assertDatabaseCount('users', 2);
    expect(User::where('crm_id', 'crm-ivan')->firstOrFail()->toArray())->not->toHaveKey('crm_password_hash');
});

test('legacy MySQL metadata imports prefixed CRM users without requiring modern schema columns', function () {
    $crm = seedCrmUsers([[]]);
    $columns = $crm->getSchemaBuilder()->getColumnListing('users');
    $database = $crm->getDatabaseName();
    $crm->getSchemaBuilder()->rename('users', 'sugar_users');
    $pdo = $crm->getPdo();
    $pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
    $pdo->exec('CREATE TABLE information_schema.columns (table_schema TEXT, table_name TEXT, column_name TEXT, ordinal_position INTEGER)');
    $insert = $pdo->prepare('INSERT INTO information_schema.columns VALUES (?, ?, ?, ?)');

    foreach ($columns as $position => $column) {
        $insert->execute([$database, 'sugar_users', $column, $position]);
    }

    $pdo->exec('PRAGMA query_only = ON');
    DB::purge('crm');
    config(['database.connections.crm.driver' => 'mysql', 'database.connections.crm.prefix' => 'sugar_']);
    DB::extend('crm', fn (array $config, string $name): MySqlConnection => new MySqlConnection(
        $pdo, $database, 'sugar_', array_replace($config, ['name' => $name]),
    ));

    $this->artisan('crm:sync-users')->assertSuccessful();

    $this->assertDatabaseHas('users', ['crm_id' => 'crm-ivan', 'crm_username' => 'ivan', 'crm_active' => true]);
    expect(DB::connection('crm')->table('users')->count())->toBe(1);
});

test('repeated synchronization updates the same user and preserves their conversations', function () {
    $crm = seedCrmUsers([[]]);
    $this->artisan('crm:sync-users')->assertSuccessful();
    $user = User::where('crm_id', 'crm-ivan')->firstOrFail();
    $chat = Chat::create(['type' => 'direct']);
    $chat->users()->attach($user->id);
    $message = $chat->messages()->create(['user_id' => $user->id, 'body' => 'История']);
    $crm->table('users')->where('id', 'crm-ivan')->update([
        'user_name' => 'ivan.new',
        'first_name' => 'Новое имя',
        'user_hash' => md5('new-crm-password'),
    ]);

    $this->artisan('crm:sync-users')->assertSuccessful();
    $this->artisan('crm:sync-users')->assertSuccessful();

    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'crm_id' => 'crm-ivan',
        'crm_username' => 'ivan.new',
        'name' => 'Новое имя Петров',
        'crm_password_hash' => md5('new-crm-password'),
    ]);
    $this->assertDatabaseHas('messages', ['id' => $message->id, 'user_id' => $user->id]);
    $this->assertDatabaseHas('chat_users', ['chat_id' => $chat->id, 'user_id' => $user->id]);
});

test('a later synchronization adds employees created in CRM after the initial import', function () {
    $crm = seedCrmUsers([[]]);
    $this->artisan('crm:sync-users')->assertSuccessful();
    $firstUser = User::where('crm_id', 'crm-ivan')->firstOrFail();
    $crm->table('users')->insert([
        'id' => 'crm-anna',
        'user_name' => 'anna',
        'user_hash' => md5('anna-password'),
        'first_name' => 'Анна',
        'last_name' => 'Иванова',
        'status' => 'Active',
    ]);

    $this->artisan('crm:sync-users')->assertSuccessful();

    $this->assertDatabaseCount('users', 2);
    $this->assertDatabaseHas('users', ['id' => $firstUser->id, 'crm_id' => 'crm-ivan']);
    $this->assertDatabaseHas('users', [
        'crm_id' => 'crm-anna',
        'crm_username' => 'anna',
        'name' => 'Анна Иванова',
        'crm_password_hash' => md5('anna-password'),
        'crm_active' => true,
    ]);

    $this->actingAs($firstUser);
    Livewire::test('pages::chat')->assertSee('Анна Иванова');
});

test('initial synchronization excludes inactive and non employee CRM accounts', function () {
    seedCrmUsers([
        ['id' => 'crm-group', 'user_name' => '', 'is_group' => true],
        ['id' => 'crm-inactive', 'user_name' => '', 'status' => 'Inactive'],
        ['id' => '', 'user_name' => '', 'deleted' => true],
        [],
    ]);

    $this->artisan('crm:sync-users')->assertSuccessful();

    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseHas('users', ['crm_id' => 'crm-ivan']);
    $this->assertDatabaseMissing('users', ['crm_id' => 'crm-group']);
    $this->assertDatabaseMissing('users', ['crm_id' => 'crm-inactive']);
});

test('synchronization disables unavailable CRM users and revokes remembered access', function (array $changes) {
    seedCrmUsers([$changes]);
    $user = User::factory()->create([
        'crm_id' => 'crm-ivan',
        'crm_username' => 'ivan',
        'crm_password_hash' => md5('crm-password'),
        'remember_token' => 'remembered-token',
    ]);

    $this->artisan('crm:sync-users')->assertSuccessful();

    $this->assertDatabaseHas('users', ['id' => $user->id, 'crm_active' => false, 'remember_token' => null]);
    expect(Auth::guard()->getProvider()->retrieveById($user->id))->toBeNull();
    expect(Auth::guard()->getProvider()->retrieveByToken($user->id, 'remembered-token'))->toBeNull();
})->with([
    'inactive user' => [['status' => 'Inactive', 'user_name' => '']],
    'deleted user' => [['deleted' => true, 'user_name' => '']],
    'group user' => [['is_group' => true]],
    'portal user' => [['portal_only' => true]],
    'external authentication user' => [['external_auth_only' => true]],
]);

test('an active employee with invalid identity cancels the import with a specific explanation', function (array $changes, string $reason) {
    seedCrmUsers([$changes]);
    $localUser = User::factory()->create();

    $this->artisan('crm:sync-users')
        ->expectsOutputToContain('Запись CRM №1: у активного сотрудника '.$reason)
        ->assertFailed();

    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseHas('users', ['id' => $localUser->id, 'crm_active' => true]);
})->with([
    'missing ID' => [['id' => ''], 'пустой ID'],
    'oversized ID' => [['id' => str_repeat('a', 37)], 'пустой ID'],
    'missing username' => [['user_name' => '   '], 'пустой user_name'],
    'oversized username' => [['user_name' => str_repeat('a', 256)], 'пустой user_name'],
]);

test('users missing from a complete CRM snapshot are disabled without deleting local users', function () {
    seedCrmUsers([['id' => 'crm-other', 'user_name' => 'other']]);
    $missingUser = User::factory()->create(['crm_id' => 'crm-missing', 'crm_username' => 'missing']);
    $localUser = User::factory()->create();

    $this->artisan('crm:sync-users')->assertSuccessful();

    $this->assertDatabaseHas('users', ['id' => $missingUser->id, 'crm_active' => false]);
    $this->assertDatabaseHas('users', ['id' => $localUser->id, 'crm_active' => true, 'crm_id' => null]);
});

test('unsupported password hashes roll back the entire synchronization', function () {
    seedCrmUsers([
        ['first_name' => 'Изменённое имя'],
        ['id' => 'crm-invalid', 'user_name' => 'invalid', 'user_hash' => 'unsupported-hash'],
    ]);
    $user = User::factory()->create([
        'name' => 'Исходное имя',
        'crm_id' => 'crm-ivan',
        'crm_username' => 'ivan',
        'crm_password_hash' => md5('previous-password'),
    ]);

    $this->artisan('crm:sync-users')->assertFailed();

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'name' => 'Исходное имя',
        'crm_active' => true,
        'crm_password_hash' => md5('previous-password'),
    ]);
    $this->assertDatabaseMissing('users', ['crm_id' => 'crm-invalid']);
});

test('an empty CRM table does not disable previously imported users', function () {
    seedCrmUsers([]);
    $user = User::factory()->create(['crm_id' => 'crm-ivan', 'crm_username' => 'ivan']);

    $this->artisan('crm:sync-users')->assertFailed();

    $this->assertDatabaseHas('users', ['id' => $user->id, 'crm_active' => true]);
});

test('an unavailable source leaves existing users unchanged', function () {
    $crm = seedCrmUsers([[]]);
    $crm->getSchemaBuilder()->drop('users');
    $user = User::factory()->create(['crm_id' => 'crm-ivan', 'crm_username' => 'ivan']);

    $this->artisan('crm:sync-users')->assertFailed();

    $this->assertDatabaseHas('users', ['id' => $user->id, 'crm_active' => true]);
});

test('dry run verifies the import without changing local data', function () {
    seedCrmUsers([[]]);
    $localUser = User::factory()->create();

    $this->artisan('crm:sync-users --dry-run')
        ->expectsOutput('Подключение к CRM и чтение структуры users...')
        ->expectsOutput('Подключение к базе чата и начало транзакции...')
        ->expectsOutput('Чтение сотрудников из CRM...')
        ->expectsOutput('Обработано пользователей: 1. Новых: 1.')
        ->expectsOutput('Проверка завершена: 1 пользователей. Изменения не сохранены.')
        ->assertSuccessful();

    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseHas('users', ['id' => $localUser->id, 'crm_id' => null]);
});

test('database failures explain the constraint and log safe diagnostics without exposing hashes', function () {
    seedCrmUsers([
        [],
        ['id' => 'crm-duplicate', 'user_name' => 'ivan'],
    ]);
    Log::spy();

    $this->artisan('crm:sync-users')
        ->expectsOutputToContain('SQLSTATE: 23000; код: 19. Нарушено ограничение таблицы.')
        ->doesntExpectOutputToContain(md5('crm-password'))
        ->assertFailed();

    $this->assertDatabaseCount('users', 0);
    Log::shouldHaveReceived('error')->once()->with('CRM user synchronization failed.', [
        'exception_type' => UniqueConstraintViolationException::class,
        'connection' => config('database.default'),
        'sqlstate' => '23000',
        'driver_code' => 19,
        'reason' => 'Нарушено ограничение таблицы. Проверьте повторяющиеся логины и обязательные поля.',
    ]);
});

test('synchronization refuses to use the local database as its CRM source', function () {
    config(['database.connections.crm' => config('database.connections.'.config('database.default'))]);
    DB::purge('crm');
    $user = User::factory()->create();

    $this->artisan('crm:sync-users')->assertFailed();

    $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => $user->name]);
});

test('imported users can log in and confirm their CRM password without changing its hash', function () {
    seedCrmUsers([[]]);
    $this->artisan('crm:sync-users')->assertSuccessful();
    $user = User::where('crm_id', 'crm-ivan')->firstOrFail();

    $this->post(route('login.store'), ['email' => 'Ivan', 'password' => 'crm-password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('chat.index', absolute: false));

    $this->assertAuthenticatedAs($user);
    $this->get(route('profile.edit'))->assertOk();
    $this->post(route('password.confirm.store'), ['password' => 'crm-password'])
        ->assertSessionHasNoErrors();
    $this->assertDatabaseHas('users', ['id' => $user->id, 'crm_password_hash' => md5('crm-password')]);
});

test('imported users cannot log in with an incorrect password or the password hash', function (string $password) {
    seedCrmUsers([[]]);
    $this->artisan('crm:sync-users')->assertSuccessful();

    $this->post(route('login.store'), ['email' => 'ivan', 'password' => $password])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
})->with(['incorrect password' => 'wrong-password', 'hash as password' => md5('crm-password')]);

test('synchronization revokes database sessions after a CRM password change', function () {
    config(['session.driver' => 'database']);
    seedCrmUsers([[]]);
    $user = User::factory()->create([
        'crm_id' => 'crm-ivan',
        'crm_username' => 'ivan',
        'crm_password_hash' => md5('previous-password'),
        'remember_token' => 'remembered-token',
    ]);
    DB::table('sessions')->insert([
        'id' => 'crm-session',
        'user_id' => $user->id,
        'payload' => '',
        'last_activity' => time(),
    ]);

    $this->artisan('crm:sync-users')->assertSuccessful();

    $this->assertDatabaseMissing('sessions', ['id' => 'crm-session']);
    $this->assertDatabaseHas('users', ['id' => $user->id, 'remember_token' => null]);
});

test('imported accounts cannot change their CRM password or delete themselves in chat settings', function () {
    seedCrmUsers([[]]);
    $this->artisan('crm:sync-users')->assertSuccessful();
    $user = User::where('crm_id', 'crm-ivan')->firstOrFail();
    $this->actingAs($user);

    Livewire::test('pages::settings.security')
        ->assertSee('Пароль этой учётной записи изменяется в CRM.')
        ->assertDontSee('data-test="update-password-button"', false)
        ->call('updatePassword')
        ->assertForbidden();
    Livewire::test('pages::settings.delete-user-modal')->call('deleteUser')->assertForbidden();

    $this->assertDatabaseHas('users', ['id' => $user->id, 'crm_password_hash' => md5('crm-password')]);
});

test('ambiguous local email and CRM login cannot authenticate as the wrong account', function () {
    seedCrmUsers([['user_name' => 'ivan@example.com']]);
    User::factory()->create(['email' => 'ivan@example.com']);
    $this->artisan('crm:sync-users')->assertSuccessful();

    $this->post(route('login.store'), ['email' => 'ivan@example.com', 'password' => 'crm-password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});
